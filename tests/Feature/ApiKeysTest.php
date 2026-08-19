<?php

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

it('creates an api key and shows the plaintext once', function () {
    $tenant = Tenant::factory()->create();
    $res = $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'Shop backend', 'environment' => 'sandbox', 'abilities' => ['read', 'documents:write']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Shop backend')
        ->assertJsonPath('data.environment', 'sandbox');
    $plain = $res->json('data.key');
    expect($plain)->toStartWith('ek_test_');
    expect(ApiKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $plain))->exists())->toBeTrue();

    $list = $this->withHeaders(serviceHeaders($tenant))->getJson('/v1/api-keys')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('key')->and($list->json('data.0.prefix'))->toBe(substr($plain, 0, 12));
});

it('rejects unknown abilities and invalid environment', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'x', 'environment' => 'sandbox', 'abilities' => ['tenants:manage']])
        ->assertStatus(422);
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'x', 'environment' => 'qa', 'abilities' => ['read']])
        ->assertStatus(422);
});

it('authenticates with an api key bound to its tenant and environment', function () {
    $tenant = Tenant::factory()->create();
    ['plaintext' => $plain] = ApiKey::generate($tenant, 'k', Environment::Sandbox, ['read']);
    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => Tenant::factory()->create()->id])
        ->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.tenant.id', $tenant->id)   // header ignored
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.actor.type', 'api_key');
});

it('cannot list another tenant\'s keys', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    ApiKey::generate($a, 'a-key', Environment::Sandbox, ['read']);
    $this->withHeaders(serviceHeaders($b))->getJson('/v1/api-keys')->assertOk()->assertJsonCount(0, 'data');
});

it('revokes a key so it no longer authenticates', function () {
    $tenant = Tenant::factory()->create();
    ['key' => $key, 'plaintext' => $plain] = ApiKey::generate($tenant, 'k', Environment::Production, ['read']);
    $this->withHeaders(serviceHeaders($tenant))->deleteJson("/v1/api-keys/{$key->id}")->assertNoContent();
    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/v1/me')->assertStatus(401);
});

it('returns 404 when revoking another tenant\'s key', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    ['key' => $key] = ApiKey::generate($a, 'k', Environment::Production, ['read']);
    $this->withHeaders(serviceHeaders($b))->deleteJson("/v1/api-keys/{$key->id}")->assertStatus(404);
});

it('lets an api key with issuers:manage create more keys for its own tenant only', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'child', 'environment' => 'sandbox', 'abilities' => ['read']])
        ->assertCreated();
    expect(ApiKey::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('returns each key exactly once with a deterministic cursor order when created_at ties', function () {
    $tenant = Tenant::factory()->create();
    Carbon::setTestNow('2026-01-01 00:00:00');
    $ids = [
        ApiKey::generate($tenant, 'a', Environment::Sandbox, ['read'])['key']->id,
        ApiKey::generate($tenant, 'b', Environment::Sandbox, ['read'])['key']->id,
        ApiKey::generate($tenant, 'c', Environment::Sandbox, ['read'])['key']->id,
    ];
    Carbon::setTestNow();

    $res = $this->withHeaders(serviceHeaders($tenant))->getJson('/v1/api-keys')->assertOk();

    expect($res->json())->toHaveKeys(['data', 'links', 'meta']);
    $returnedIds = collect($res->json('data'))->pluck('id')->all();
    expect($returnedIds)->toHaveCount(3);
    expect(array_unique($returnedIds))->toHaveCount(3);
    expect($returnedIds)->toBe(collect($ids)->sortDesc()->values()->all());
});

it('requires X-Tenant-Id for a service token before route binding is attempted', function () {
    $tenant = Tenant::factory()->create();
    ['key' => $key] = ApiKey::generate($tenant, 'k', Environment::Production, ['read']);
    $this->withHeaders(['Authorization' => 'Bearer '.serviceToken()])
        ->deleteJson("/v1/api-keys/{$key->id}")
        ->assertStatus(400)
        ->assertJsonPath('code', 'tenant_header_required');
});

it('forbids an api key from creating a key for another environment', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'prod', 'environment' => 'production', 'abilities' => ['read']])
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
    expect(ApiKey::withoutGlobalScopes()->where('environment', Environment::Production)->count())->toBe(0);
});

it('lists only its own environment for an api key', function () {
    $tenant = Tenant::factory()->create();
    $headers = apiKeyHeaders($tenant); // sandbox key
    ['key' => $prod] = ApiKey::generate($tenant, 'prod', Environment::Production, ['read']);
    ['key' => $sandbox] = ApiKey::generate($tenant, 'sandbox', Environment::Sandbox, ['read']);

    $ids = collect($this->withHeaders($headers)->getJson('/v1/api-keys')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($sandbox->id)->not->toContain($prod->id);

    // A service token still sees every environment for the tenant.
    $all = collect($this->withHeaders(serviceHeaders($tenant))->getJson('/v1/api-keys')->assertOk()->json('data'))->pluck('id')->all();
    expect($all)->toContain($prod->id)->toContain($sandbox->id);
});

it('returns 404 when an api key revokes a key from another environment', function () {
    $tenant = Tenant::factory()->create();
    $headers = apiKeyHeaders($tenant); // sandbox key
    ['key' => $prod] = ApiKey::generate($tenant, 'prod', Environment::Production, ['read']);

    $this->withHeaders($headers)->deleteJson("/v1/api-keys/{$prod->id}")->assertStatus(404);
    expect($prod->refresh()->revoked_at)->toBeNull();
});

it('lets a service token create a key for either environment regardless of X-Environment', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/api-keys', ['name' => 'prod', 'environment' => 'production', 'abilities' => ['read']])
        ->assertCreated()
        ->assertJsonPath('data.environment', 'production');
});
