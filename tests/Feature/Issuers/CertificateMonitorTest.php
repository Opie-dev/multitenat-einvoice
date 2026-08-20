<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Enums\WebhookEvent;
use App\Events\CertificateExpiring;
use App\Jobs\PrepareDocument;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

$fx = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

/** @param array<int, string> $events */
function certWebhookEndpoint(Tenant $tenant, array $events): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($tenant)->create([
        'environment' => Environment::Sandbox,
        'url' => 'https://hooks.example.test/hook',
        'secret' => 'whsec_known',
        'events' => $events,
        'enabled' => true,
    ]);
}

it('sends a certificate.expiring notice at the 30-day threshold and does not duplicate it same-day', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDays(29);
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $secret = $issuer->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    app(TenantContext::class)->clear();

    $endpoint = certWebhookEndpoint($tenant, [WebhookEvent::CertificateExpiring->value]);

    Artisan::call('einvoice:monitor-certificates');

    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->get();
    expect($deliveries)->toHaveCount(1);
    expect($deliveries->first()->event)->toBe('certificate.expiring');
    expect($deliveries->first()->payload['data']['days_left'])->toBe(29);
    expect($deliveries->first()->payload['data']['expires_at'])->not->toBeNull();
    expect(AuditLog::where('action', 'certificate.expiring')->count())->toBe(1);
    expect($secret->fresh()->expiry_notified_at_days)->toBe(30);

    // Second run the same day must not send a duplicate notice.
    Artisan::call('einvoice:monitor-certificates');

    expect(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'certificate.expiring')->count())->toBe(1);
});

it('sends a new expiring notice once the 7-day threshold is crossed', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDays(29);
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $secret = $issuer->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    app(TenantContext::class)->clear();
    $endpoint = certWebhookEndpoint($tenant, [WebhookEvent::CertificateExpiring->value]);

    Artisan::call('einvoice:monitor-certificates'); // 30-day notice

    $this->travel(23)->days(); // 29 - 23 = 6 days left

    Artisan::call('einvoice:monitor-certificates');

    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->orderBy('id')->get();
    expect($deliveries)->toHaveCount(2);
    expect($deliveries->last()->payload['data']['days_left'])->toBe(6);
    expect($secret->fresh()->expiry_notified_at_days)->toBe(7);
});

it('suspends the issuer and fires certificate.expired once the certificate lapses', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDays(2);
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    app(TenantContext::class)->clear();
    $endpoint = certWebhookEndpoint($tenant, [WebhookEvent::CertificateExpired->value, WebhookEvent::IssuerStatusChanged->value]);

    $this->travel(3)->days(); // past validUntil

    Artisan::call('einvoice:monitor-certificates');

    expect($issuer->fresh()->status)->toBe(IssuerStatus::Suspended);

    $events = WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toContain('certificate.expired')->toContain('issuer.status_changed');
    expect(AuditLog::where('action', 'certificate.expired')->count())->toBe(1);

    $statusDelivery = WebhookDelivery::withoutGlobalScopes()
        ->where('webhook_endpoint_id', $endpoint->id)->where('event', 'issuer.status_changed')->first();
    expect($statusDelivery->payload['data']['status_from'])->toBe('active')
        ->and($statusDelivery->payload['data']['status_to'])->toBe('suspended');
});

it('does not re-suspend or re-notify an already-suspended issuer', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDay();
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    app(TenantContext::class)->clear();
    $endpoint = certWebhookEndpoint($tenant, [WebhookEvent::CertificateExpired->value]);

    $this->travel(2)->days();
    Artisan::call('einvoice:monitor-certificates');
    expect($issuer->fresh()->status)->toBe(IssuerStatus::Suspended);

    Artisan::call('einvoice:monitor-certificates'); // already suspended: no second expired event

    expect(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->count())->toBe(1);
});

it('reactivates the issuer and resets expiry_notified_at_days on a new certificate upload', function () use ($fx) {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDay();
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $secret = $issuer->secret()->create([
        'signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil, 'expiry_notified_at_days' => 7,
    ]);
    app(TenantContext::class)->clear();

    $this->travel(2)->days();
    Artisan::call('einvoice:monitor-certificates');
    expect($issuer->fresh()->status)->toBe(IssuerStatus::Suspended);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", [
            'format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem'),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($issuer->fresh()->status)->toBe(IssuerStatus::Active);
    expect($secret->fresh()->expiry_notified_at_days)->toBeNull();
});

it('holds queued documents when the issuer is suspended and releases them on re-upload', function () use ($fx) {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDay();
    $issuer = Issuer::factory()->for($tenant)->active()->create([
        'environment' => Environment::Sandbox,
        'certificate_valid_until' => $validUntil,
    ]);
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    $document = Document::factory()->for($issuer)->queued()->create(['environment' => Environment::Sandbox]);
    app(TenantContext::class)->clear();

    // The release path re-queues the document; the submission pipeline itself is not under test here.
    Queue::fake([PrepareDocument::class]);

    $this->travel(2)->days();
    Artisan::call('einvoice:monitor-certificates');

    expect($issuer->fresh()->status)->toBe(IssuerStatus::Suspended);
    expect($document->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($document->held_reason)->toBe(HeldReason::CertificateExpired);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", [
            'format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem'),
        ])
        ->assertOk();

    expect($document->refresh()->status)->toBe(DocumentStatus::Queued);
});

it('keeps sweeping when one issuer throws, records the notice anyway and exits non-zero', function () {
    Log::spy();
    $tenant = Tenant::factory()->create();
    $validUntil = now()->addDays(29);

    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $bad = Issuer::factory()->for($tenant)->active()->create(['environment' => Environment::Sandbox, 'certificate_valid_until' => $validUntil]);
    $badSecret = $bad->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    $good = Issuer::factory()->for($tenant)->active()->create(['environment' => Environment::Sandbox, 'certificate_valid_until' => $validUntil]);
    $goodSecret = $good->secret()->create(['signing_certificate' => 'x', 'signing_key' => 'y', 'cert_not_after' => $validUntil]);
    app(TenantContext::class)->clear();

    Event::listen(CertificateExpiring::class, function (CertificateExpiring $event) use ($bad): void {
        if ($event->issuer->id === $bad->id) {
            throw new RuntimeException('notification pipeline down');
        }
    });

    expect(Artisan::call('einvoice:monitor-certificates'))->toBe(1);

    // The dedupe marker is written before the notice is dispatched, so a listener
    // that blows up cannot make the next run re-send the same threshold's notice.
    expect($badSecret->fresh()->expiry_notified_at_days)->toBe(30);
    // The failure is contained: the next issuer in the sweep is still processed.
    expect($goodSecret->fresh()->expiry_notified_at_days)->toBe(30);

    // report() logs too, so the matcher has to tolerate other error calls.
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => $message === 'certificate.monitor_skipped'
        && ($context['tenant_id'] ?? null) === $tenant->id
        && ($context['issuer_id'] ?? null) === $bad->id
        && ($context['environment'] ?? null) === 'sandbox'
        && str_contains((string) ($context['exception'] ?? ''), 'notification pipeline down'));
});

it('prunes submission attempts older than the retention window and keeps the rest', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    $old = SubmissionAttempt::factory()->for($issuer)->create(['created_at' => now()->subDays(31)]);
    $recent = SubmissionAttempt::factory()->for($issuer)->create(['created_at' => now()->subDays(5)]);
    app(TenantContext::class)->clear();

    Artisan::call('einvoice:prune-attempts', ['--days' => 30]);
    $output = Artisan::output();

    expect($output)->toContain('Pruned 1 submission attempt');
    expect(SubmissionAttempt::withoutGlobalScopes()->pluck('id')->all())->toBe([$recent->id]);
    expect(SubmissionAttempt::withoutGlobalScopes()->find($old->id))->toBeNull();
});
