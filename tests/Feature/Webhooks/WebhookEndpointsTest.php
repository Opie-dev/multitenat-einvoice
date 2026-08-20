<?php

use App\Enums\Environment;
use App\Enums\WebhookEvent;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

it('creates a webhook endpoint and shows the secret once', function () {
    $tenant = Tenant::factory()->create();
    $res = $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/webhooks', [
            'url' => 'https://example.com/hooks/einvoice',
            'events' => [WebhookEvent::DocumentValid->value, WebhookEvent::DocumentInvalid->value],
            'description' => 'Primary hook',
        ])
        ->assertCreated()
        ->assertJsonPath('data.url', 'https://example.com/hooks/einvoice')
        ->assertJsonPath('data.events', [WebhookEvent::DocumentValid->value, WebhookEvent::DocumentInvalid->value])
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.description', 'Primary hook');

    $secret = $res->json('data.secret');
    expect($secret)->toStartWith('whsec_');

    $endpoint = WebhookEndpoint::withoutGlobalScopes()->where('url', 'https://example.com/hooks/einvoice')->firstOrFail();
    expect($endpoint->secret)->toBe($secret);
});

it('allows non-https urls only for localhost', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/webhooks', ['url' => 'http://127.0.0.1:8080/hook', 'events' => [WebhookEvent::DocumentValid->value]])
        ->assertCreated();
});

it('lists webhook endpoints without the secret and only for the current environment', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox'))
        ->postJson('/v1/webhooks', ['url' => 'https://example.com/hook', 'events' => [WebhookEvent::DocumentValid->value]])
        ->assertCreated();
    WebhookEndpoint::factory()->for($tenant)->create(['environment' => Environment::Production]);

    $list = $this->withHeaders(apiKeyHeaders($tenant, 'sandbox'))->getJson('/v1/webhooks')->assertOk();

    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0'))->not->toHaveKey('secret');
});

it('updates events and the enabled flag', function () {
    $tenant = Tenant::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($tenant)->create([
        'environment' => Environment::Sandbox,
        'events' => [WebhookEvent::DocumentValid->value],
        'enabled' => true,
    ]);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->patchJson("/v1/webhooks/{$endpoint->id}", [
            'events' => [WebhookEvent::DocumentInvalid->value, WebhookEvent::DocumentRejected->value],
            'enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.events', [WebhookEvent::DocumentInvalid->value, WebhookEvent::DocumentRejected->value]);

    expect($endpoint->refresh()->enabled)->toBeFalse();
});

it('deletes a webhook endpoint', function () {
    $tenant = Tenant::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($tenant)->create(['environment' => Environment::Sandbox]);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->deleteJson("/v1/webhooks/{$endpoint->id}")->assertNoContent();

    expect(WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id))->toBeNull();
});

it('rejects an unknown event name with a pointer to the offending index', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/webhooks', ['url' => 'https://example.com/hook', 'events' => ['not.a.real.event']])
        ->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/events/0']);
});

it('rejects a non-https url for a public host', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/webhooks', ['url' => 'http://example.com/hook', 'events' => [WebhookEvent::DocumentValid->value]])
        ->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/url']);
});

it('returns 404 for another tenant\'s webhook endpoint', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($a)->create(['environment' => Environment::Sandbox]);

    $this->withHeaders(serviceHeaders($b, 'sandbox'))->getJson("/v1/webhooks/{$endpoint->id}")->assertStatus(404);
});

it('returns 404 for a webhook endpoint bound to another environment', function () {
    $tenant = Tenant::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($tenant)->create(['environment' => Environment::Production]);

    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox'))->getJson("/v1/webhooks/{$endpoint->id}")->assertStatus(404);
});

it('requires the webhooks:manage ability', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->getJson('/v1/webhooks')
        ->assertStatus(403);
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->postJson('/v1/webhooks', ['url' => 'https://example.com/hook', 'events' => [WebhookEvent::DocumentValid->value]])
        ->assertStatus(403);
});

it('lists deliveries for a webhook endpoint scoped to the tenant', function () {
    $tenant = Tenant::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($tenant)->create(['environment' => Environment::Sandbox]);
    $other = WebhookEndpoint::factory()->for($tenant)->create(['environment' => Environment::Sandbox]);
    $delivery = WebhookDelivery::factory()->for($tenant)->for($endpoint, 'endpoint')->create();
    WebhookDelivery::factory()->for($tenant)->for($other, 'endpoint')->create();

    $res = $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->getJson("/v1/webhooks/{$endpoint->id}/deliveries")
        ->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.id'))->toBe($delivery->id);
});
