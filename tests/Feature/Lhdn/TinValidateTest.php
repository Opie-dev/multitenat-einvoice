<?php

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Sandbox]);
    $this->h = apiKeyHeaders($this->tenant, 'sandbox');
});

it('validates a TIN, caches the result for 24h, and reports cache hits', function () {
    $body = ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '123456'];
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.cached', false);
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.cached', true);
    expect(collect(fakeLhdn()->calls())->where('operation', 'validate_tin'))->toHaveCount(1);
    fakeLhdn()->invalidTin('C0000000000');
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', ['tin' => 'C0000000000', 'id_type' => 'NRIC', 'id_number' => '900101011234'])->assertOk()->assertJsonPath('data.valid', false);
});

it('validates input and requires an issuer to exist', function () {
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', ['tin' => 'C1', 'id_type' => 'XX', 'id_number' => '1'])->assertStatus(422)->assertJsonFragment(['pointer' => '/id_type']);
    $empty = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($empty, 'sandbox'))->postJson('/v1/tin/validate', ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '1'])->assertStatus(409)->assertJsonPath('code', 'issuer_required');
});

it('scopes the cache per tenant and environment and respects abilities', function () {
    $body = ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '123456'];
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk();
    $other = Tenant::factory()->create();
    Issuer::factory()->for($other)->active()->create();
    $this->withHeaders(apiKeyHeaders($other, 'sandbox'))->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.cached', false);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['documents:write']))->postJson('/v1/tin/validate', $body)->assertStatus(403);
});
