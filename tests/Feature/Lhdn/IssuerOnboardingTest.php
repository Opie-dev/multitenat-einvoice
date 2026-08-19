<?php

use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Lhdn\LhdnException;
use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;

$certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->issuer = Issuer::factory()->for($this->tenant)->create(['environment' => Environment::Sandbox]); // draft, intermediary
    $this->h = serviceHeaders($this->tenant, 'sandbox');
});

it('verifies the issuer TIN via LHDN and moves draft → tin_verified', function () {
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")
        ->assertOk()->assertJsonPath('data.status', 'tin_verified')->assertJsonPath('data.tin_verified_at', fn ($v) => $v !== null);
    expect(collect(fakeLhdn()->calls())->last()['operation'])->toBe('validate_tin')
        ->and(AuditLog::where('action', 'issuer.tin_verified')->count())->toBe(1);
});

it('returns 422 tin_invalid when LHDN does not know the TIN', function () {
    fakeLhdn()->invalidTin($this->issuer->tin);
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(422)->assertJsonPath('code', 'tin_invalid');
    expect($this->issuer->refresh()->status)->toBe(IssuerStatus::Draft);
});

it('requires credentials first for own_credentials issuers and maps LHDN outages to 503', function () {
    $own = Issuer::factory()->for($this->tenant)->create(['lhdn_mode' => LhdnMode::OwnCredentials]);
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$own->id}/verify-tin")->assertStatus(409)->assertJsonPath('code', 'lhdn_credentials_invalid');
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503));
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(503)->assertJsonPath('code', 'lhdn_unavailable');
});

it('authorizes after TIN verification and activates when a certificate exists', function () use ($certs) {
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(409)->assertJsonPath('code', 'tin_not_verified');
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertOk();
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertOk()->assertJsonPath('data.status', 'authorized');
    expect(collect(fakeLhdn()->calls())->last()['operation'])->toBe('token')->and(AuditLog::where('action', 'issuer.authorized')->count())->toBe(1);
    $this->withHeaders($this->h)->putJson("/v1/issuers/{$this->issuer->id}/certificate", ['format' => 'pem', 'certificate' => $certs('test-cert.pem'), 'private_key' => $certs('test-key.pem')])
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('reports credential failures during authorize as 409 lhdn_credentials_invalid', function () {
    $this->issuer->forceFill(['tin_verified_at' => now(), 'status' => IssuerStatus::TinVerified])->save();
    fakeLhdn()->failNextWith(LhdnException::auth('invalid_client', 401), 'token');
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(409)->assertJsonPath('code', 'lhdn_credentials_invalid')
        ->assertJsonPath('detail', fn ($d) => str_contains($d, 'intermediary'));
    expect($this->issuer->refresh()->status)->toBe(IssuerStatus::TinVerified);
});

it('is tenant/environment scoped and needs issuers:manage', function () {
    $this->withHeaders(serviceHeaders(Tenant::factory()->create(), 'sandbox'))->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['read']))->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(403);
});
