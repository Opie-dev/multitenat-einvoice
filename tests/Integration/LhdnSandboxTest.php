<?php

use App\Enums\Environment;
use App\Enums\IdType;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Opt-in only: exercises the real LHDN sandbox over the network. Skipped (not
// failed) unless LHDN_SANDBOX_TESTS=1 and sandbox intermediary credentials are
// present, so composer check stays pristine and network-free by default. See
// docs/lhdn-gateway.md#sandbox-tests for how to configure and run these.
beforeEach(function () {
    if (env('LHDN_SANDBOX_TESTS') !== '1') {
        $this->markTestSkipped('Set LHDN_SANDBOX_TESTS=1 (and LHDN_SANDBOX_* credentials) to run LHDN sandbox tests.');
    }
    config(['lhdn.driver' => 'http']);
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create([
        'tin' => (string) env('LHDN_SANDBOX_TEST_TIN'),
        'id_type' => IdType::from((string) env('LHDN_SANDBOX_TEST_ID_TYPE', 'BRN')),
        'id_number' => (string) env('LHDN_SANDBOX_TEST_ID_VALUE'),
    ]);
});

it('fetches a sandbox token as intermediary', function () {
    $token = app(LhdnClientFactory::class)->for($this->issuer)->token($this->issuer);
    expect($token->token)->not->toBe('')->and($token->isExpired())->toBeFalse();
});

it('validates the test TIN', function () {
    expect(app(LhdnClientFactory::class)->for($this->issuer)->validateTin(Environment::Sandbox, $this->issuer->tin, $this->issuer->id_type->value, $this->issuer->id_number, $this->issuer))->toBeTrue();
});

it('classifies an unknown document as a terminal error', function () {
    expect(fn () => app(LhdnClientFactory::class)->for($this->issuer)->getDocument($this->issuer, '00000000000000000000000000'))->toThrow(LhdnException::class);
});
