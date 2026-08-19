<?php

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

function issuerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Vendor One Sdn Bhd',
        'tin' => 'C12345678901',
        'id_type' => 'BRN',
        'id_number' => '202001012345',
        'sst_number' => null,
        'msic_code' => '47911',
        'business_activity_description' => 'Retail sale via internet',
        'address_line1' => '1 Jalan Test',
        'postcode' => '50000',
        'city' => 'Kuala Lumpur',
        'state_code' => '14',
        'country_code' => 'MYS',
        'email' => 'vendor@example.com',
        'phone' => '+60123456789',
        'lhdn_mode' => 'intermediary',
        'einvoice_required' => true,
        'consolidation_enabled' => true,
    ], $overrides);
}

it('creates an issuer in the credential environment with status draft', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/issuers', issuerPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.tin', 'C12345678901')
        ->assertJsonPath('data.has_certificate', false)
        ->assertJsonPath('data.has_credentials', false);
});

it('validates required fields and enums', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/issuers', issuerPayload(['id_type' => 'X', 'tin' => '']))
        ->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/id_type'])
        ->assertJsonFragment(['pointer' => '/tin']);
});

it('rejects duplicate tin per tenant and environment but allows across environments', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->postJson('/v1/issuers', issuerPayload())->assertCreated();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->postJson('/v1/issuers', issuerPayload())->assertStatus(409)
        ->assertJsonPath('code', 'issuer_exists');
    $this->withHeaders(serviceHeaders($tenant, 'production'))->postJson('/v1/issuers', issuerPayload())->assertCreated();
});

it('lists only issuers of the current tenant and environment', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    Issuer::factory()->for($a)->create(['environment' => Environment::Sandbox, 'name' => 'A-sandbox']);
    Issuer::factory()->for($a)->create(['environment' => Environment::Production, 'name' => 'A-prod']);
    Issuer::factory()->for($b)->create(['environment' => Environment::Sandbox, 'name' => 'B-sandbox']);

    $this->withHeaders(apiKeyHeaders($a, 'sandbox'))->getJson('/v1/issuers')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'A-sandbox');
});

it('returns 404 for another tenant\'s issuer or another environment\'s issuer', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $prod = Issuer::factory()->for($a)->create(['environment' => Environment::Production]);
    $this->withHeaders(apiKeyHeaders($a, 'sandbox'))->getJson("/v1/issuers/{$prod->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($b, 'production'))->getJson("/v1/issuers/{$prod->id}")->assertStatus(404);
});

it('updates mutable fields', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['environment' => Environment::Sandbox]);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->patchJson("/v1/issuers/{$issuer->id}", ['name' => 'Renamed', 'consolidation_enabled' => false])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.consolidation_enabled', false);
});

it('requires issuers:manage for writes', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->postJson('/v1/issuers', issuerPayload())->assertStatus(403);
});

it('enforces the duplicate tin rule in the database, not just the pre-check', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['environment' => Environment::Sandbox, 'tin' => 'C99999999999']);

    // The controller's pre-check can lose a race; this proves the unique index
    // behind the 409 actually exists, so the caught violation is reachable.
    expect(fn () => Issuer::factory()->for($tenant)->create([
        'environment' => $issuer->environment,
        'tin' => $issuer->tin,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
