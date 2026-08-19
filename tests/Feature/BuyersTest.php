<?php

use App\Models\Buyer;
use App\Models\Tenant;

it('creates and lists buyers per tenant', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'Ali Bin Abu', 'tin' => 'IG12345678901', 'id_type' => 'NRIC', 'id_number' => '900101011234', 'email' => 'ali@example.com'])
        ->assertCreated()->assertJsonPath('data.name', 'Ali Bin Abu')->assertJsonPath('data.general_public', false);

    Buyer::factory()->for(Tenant::factory())->create();
    $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/buyers')->assertOk()->assertJsonCount(1, 'data');
});

it('accepts a general public buyer without tin', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'General Public', 'general_public' => true])
        ->assertCreated()->assertJsonPath('data.general_public', true);
});

it('requires id_type together with id_number', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'X', 'id_number' => '123'])
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/id_type']);
});

it('updates and scopes by tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $buyer = Buyer::factory()->for($a)->create();
    $this->withHeaders(apiKeyHeaders($a))->patchJson("/v1/buyers/{$buyer->id}", ['name' => 'New'])->assertOk()->assertJsonPath('data.name', 'New');
    $this->withHeaders(apiKeyHeaders($b))->getJson("/v1/buyers/{$buyer->id}")->assertStatus(404);
});

it('requires documents:write for writes', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))->postJson('/v1/buyers', ['name' => 'X'])->assertStatus(403);
});
