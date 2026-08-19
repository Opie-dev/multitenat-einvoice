<?php

use App\Models\ServiceToken;
use App\Models\Tenant;

it('rejects requests without a bearer token', function () {
    $this->getJson('/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('rejects unknown tokens', function () {
    $this->withHeader('Authorization', 'Bearer sk_catalog_nope')->getJson('/v1/me')->assertStatus(401);
});

it('rejects revoked service tokens', function () {
    ['token' => $t, 'plaintext' => $plain] = ServiceToken::generate('catalog');
    $t->update(['revoked_at' => now()]);
    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/v1/me')->assertStatus(401);
});

it('authenticates a service token and requires X-Tenant-Id on tenant routes', function () {
    $tenant = Tenant::factory()->create();
    $plain = serviceToken();

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/v1/me')
        ->assertStatus(400)
        ->assertJsonPath('code', 'tenant_header_required');

    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => $tenant->id])
        ->getJson('/v1/me')
        ->assertOk()
        ->assertJsonPath('data.actor.type', 'service')
        ->assertJsonPath('data.tenant.id', $tenant->id)
        ->assertJsonPath('data.environment', 'production');
});

it('returns 404 for an unknown X-Tenant-Id', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.serviceToken(), 'X-Tenant-Id' => '01J00000000000000000000000'])
        ->getJson('/v1/me')->assertStatus(404)->assertJsonPath('code', 'tenant_not_found');
});

it('honours X-Environment for service tokens', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->getJson('/v1/me')
        ->assertOk()->assertJsonPath('data.environment', 'sandbox');
    $this->withHeaders(serviceHeaders($tenant, 'staging'))->getJson('/v1/me')
        ->assertStatus(400)->assertJsonPath('code', 'invalid_environment');
});

it('enforces abilities', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, abilities: ['read']))
        ->postJson('/v1/tenants', ['name' => 'X'])
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('updates last_used_at', function () {
    $tenant = Tenant::factory()->create();
    ['token' => $t, 'plaintext' => $plain] = ServiceToken::generate('recurring');
    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => $tenant->id])->getJson('/v1/me')->assertOk();
    expect($t->fresh()->last_used_at)->not->toBeNull();
});
