<?php

use App\Enums\Environment;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** @param array<string, mixed> $overrides */
function webhookEndpointFor(Tenant $tenant, array $overrides = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($tenant)->create(array_merge([
        'environment' => Environment::Sandbox,
        'url' => 'https://hooks.example.test/hook',
        'secret' => 'whsec_known',
        'events' => WebhookEvent::values(),
        'enabled' => true,
    ], $overrides));
}

/** @param array<string, mixed> $payload */
function pendingDelivery(WebhookEndpoint $endpoint, array $payload, ?string $event = null): WebhookDelivery
{
    return WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => $event ?? WebhookEvent::DocumentValid->value,
        'payload' => $payload,
        'status' => WebhookDeliveryStatus::Pending,
        'attempt' => 0,
    ]);
}

it('delivers a webhook with a body-verifiable signature', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $payload = ['id' => 'evt_1', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => ['foo' => 'bar']];
    $created = app(WebhookDispatcher::class)->dispatch('document.valid', Environment::Sandbox, $payload);
    app(TenantContext::class)->clear();

    expect($created)->toBe(1);
    $delivery = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->firstOrFail();

    $expectedBody = (string) json_encode($payload);
    $expectedSignature = hash_hmac('sha256', $expectedBody, 'whsec_known');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/hook'
        && ($request->header('X-Einvoice-Event')[0] ?? null) === 'document.valid'
        && ($request->header('X-Einvoice-Signature')[0] ?? null) === $expectedSignature
        && ($request->header('User-Agent')[0] ?? null) === 'billplz-einvoice/1.0'
        && $request->body() === $expectedBody);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->http_status)->toBe(200)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('marks a delivery retrying and re-dispatches with a delay after a non-2xx response', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response('server error', 500)]);
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $delivery = pendingDelivery($endpoint, ['id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => []]);

    Queue::fake();
    (new DeliverWebhook($delivery->id))->handle();
    app(TenantContext::class)->clear();

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Retrying)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->http_status)->toBe(500)
        ->and($delivery->response_snippet)->toContain('server error')
        ->and($delivery->next_retry_at)->not->toBeNull();

    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $delivery->id && $job->delay !== null);
});

it('exhausts a delivery once the backoff curve is spent', function () {
    // With a single-entry backoff curve, two failures exhaust the delivery. On the
    // sync queue connection a retry's self-dispatch runs inline (delay is ignored),
    // so both failures happen within this one top-level handle() call.
    config(['einvoice.webhooks.backoff_seconds' => [1]]);
    Http::fake(['https://hooks.example.test/*' => Http::response('nope', 500)]);
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $delivery = pendingDelivery($endpoint, ['id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => []]);

    (new DeliverWebhook($delivery->id))->handle();
    app(TenantContext::class)->clear();

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Exhausted)->and($delivery->attempt)->toBe(2);
    Http::assertSentCount(2);
});

it('creates no delivery for a disabled endpoint', function () {
    $tenant = Tenant::factory()->create();
    webhookEndpointFor($tenant, ['enabled' => false]);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $created = app(WebhookDispatcher::class)->dispatch('document.valid', Environment::Sandbox, [
        'id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => [],
    ]);
    app(TenantContext::class)->clear();

    expect($created)->toBe(0);
    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

it('does not dispatch to an endpoint that does not listen to the event', function () {
    $tenant = Tenant::factory()->create();
    webhookEndpointFor($tenant, ['events' => [WebhookEvent::DocumentValid->value]]);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $created = app(WebhookDispatcher::class)->dispatch('document.queued', Environment::Sandbox, [
        'id' => 'evt', 'event' => 'document.queued', 'created_at' => now()->toIso8601String(), 'data' => [],
    ]);
    app(TenantContext::class)->clear();

    expect($created)->toBe(0);
});

it('does not dispatch a production event to a sandbox endpoint', function () {
    $tenant = Tenant::factory()->create();
    webhookEndpointFor($tenant, ['environment' => Environment::Sandbox]);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $created = app(WebhookDispatcher::class)->dispatch('document.valid', Environment::Production, [
        'id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => [],
    ]);
    app(TenantContext::class)->clear();

    expect($created)->toBe(0);
});

it('produces webhook deliveries in order as a document moves through the pipeline via the API', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->active()->create(['environment' => Environment::Sandbox]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer->secret()->create(['signing_certificate' => $certs('test-cert.pem'), 'signing_key' => $certs('test-key.pem'), 'cert_not_after' => now()->addYears(5)]);
    app(TenantContext::class)->clear();
    $endpoint = webhookEndpointFor($tenant, ['events' => [
        WebhookEvent::DocumentValidated->value, WebhookEvent::DocumentQueued->value,
        WebhookEvent::DocumentSubmitted->value, WebhookEvent::DocumentValid->value,
    ]]);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->postJson('/v1/documents', [
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '10.00', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(4))],
    ])->assertCreated();

    $events = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['document.validated', 'document.queued', 'document.submitted', 'document.valid']);
});

it('fires issuer.status_changed as the issuer moves through onboarding', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['environment' => Environment::Sandbox]); // draft, no certificate
    $endpoint = webhookEndpointFor($tenant, ['events' => [WebhookEvent::IssuerStatusChanged->value]]);

    $h = serviceHeaders($tenant, 'sandbox');
    $this->withHeaders($h)->postJson("/v1/issuers/{$issuer->id}/verify-tin")->assertOk();
    $this->withHeaders($h)->postJson("/v1/issuers/{$issuer->id}/authorize")->assertOk();

    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->orderBy('id')->get();
    expect($deliveries)->toHaveCount(2); // draft -> tin_verified, tin_verified -> authorized (no cert yet, so no -> active)
    expect($deliveries->first()->payload['data']['status_from'])->toBe('draft')
        ->and($deliveries->first()->payload['data']['status_to'])->toBe('tin_verified')
        ->and($deliveries->last()->payload['data']['status_from'])->toBe('tin_verified')
        ->and($deliveries->last()->payload['data']['status_to'])->toBe('authorized');
});

it('dispatches a synthetic test delivery to just the target endpoint', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);
    $other = webhookEndpointFor($tenant, ['url' => 'https://hooks.example.test/other']);

    $res = $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson("/v1/webhooks/{$endpoint->id}/test")
        ->assertStatus(202);

    expect($res->json('data.event'))->toBe('webhook.test')
        ->and($res->json('data.status'))->toBe('delivered');
    expect(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(1);
    expect(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $other->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'webhook.tested')->count())->toBe(1);

    Http::assertSent(function ($request) {
        $data = json_decode((string) $request->body(), true);

        return $data['event'] === 'webhook.test' && $data['data']['message'] === 'Test delivery from Billplz E-Invoice Engine';
    });
});

it('redelivers a delivery as a new delivery with the same payload', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $original = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => WebhookEvent::DocumentValid->value,
        'payload' => ['id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => ['x' => 1]],
        'status' => WebhookDeliveryStatus::Exhausted,
        'attempt' => 7,
    ]);
    app(TenantContext::class)->clear();

    $res = $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson("/v1/webhook-deliveries/{$original->id}/redeliver")
        ->assertStatus(202);

    $newId = $res->json('data.id');
    expect($newId)->not->toBe($original->id)
        ->and($res->json('data.status'))->toBe('delivered')
        ->and($res->json('data.event'))->toBe('document.valid');

    $clone = WebhookDelivery::withoutGlobalScopes()->findOrFail($newId);
    expect($clone->payload)->toBe($original->payload)
        ->and($clone->webhook_endpoint_id)->toBe($endpoint->id);
    expect(AuditLog::where('action', 'webhook.redelivered')->count())->toBe(1);
    expect($original->refresh()->status)->toBe(WebhookDeliveryStatus::Exhausted); // original untouched
});

it('requires the webhooks:manage ability for test and redeliver', function () {
    $tenant = Tenant::factory()->create();
    $endpoint = webhookEndpointFor($tenant);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $delivery = pendingDelivery($endpoint, ['id' => 'evt', 'event' => 'document.valid', 'created_at' => now()->toIso8601String(), 'data' => []]);
    app(TenantContext::class)->clear();

    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->postJson("/v1/webhooks/{$endpoint->id}/test")->assertStatus(403);
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->postJson("/v1/webhook-deliveries/{$delivery->id}/redeliver")->assertStatus(403);
});
