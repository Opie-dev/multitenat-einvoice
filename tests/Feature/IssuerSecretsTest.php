<?php

use App\Enums\LhdnMode;
use App\Models\Issuer;
use App\Models\IssuerSecret;
use App\Models\IssuerSecretHistory;
use App\Models\Tenant;

$fx = fn (string $f) => file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('stores own credentials encrypted and never returns them', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['lhdn_mode' => LhdnMode::OwnCredentials]);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'shh'])
        ->assertOk()->assertJsonPath('data.has_credentials', true)->assertJsonMissing(['client_secret' => 'shh']);

    $raw = DB::table('issuer_secrets')->where('issuer_id', $issuer->id)->first();
    expect($raw->lhdn_client_secret)->not->toBe('shh');
    expect(IssuerSecret::withoutGlobalScopes()->where('issuer_id', $issuer->id)->first()->lhdn_client_secret)->toBe('shh');
});

it('rejects credentials for intermediary-mode issuers', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['lhdn_mode' => LhdnMode::Intermediary]);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'shh'])
        ->assertStatus(409)->assertJsonPath('code', 'credentials_not_applicable');
});

it('uploads a PEM certificate, exposes metadata only, and activates an authorized issuer', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->authorized()->create();

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", [
            'format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem'),
        ])
        ->assertOk()
        ->assertJsonPath('data.has_certificate', true)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissing(['signing_key'])
        ->assertJsonStructure(['data' => ['certificate' => ['subject', 'serial', 'fingerprint', 'not_before', 'not_after']]]);

    expect($issuer->fresh()->certificate_valid_until)->not->toBeNull();
});

it('uploads a PKCS#12 certificate', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pkcs12', 'pkcs12' => base64_encode($fx('test.p12')), 'passphrase' => 'secret'])
        ->assertOk()->assertJsonPath('data.has_certificate', true)->assertJsonPath('data.status', 'draft');
});

it('returns 422 with a code for a mismatched key', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('other-key.pem')])
        ->assertStatus(422)->assertJsonPath('code', 'key_mismatch');
});

it('archives the previous certificate on replacement', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $body = ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem')];
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/certificate", $body)->assertOk();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/certificate", $body)->assertOk();
    expect(IssuerSecretHistory::where('issuer_id', $issuer->id)->count())->toBe(1);
});

it('cannot upload to another tenant\'s issuer', function () use ($fx) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($a)->create();
    $this->withHeaders(serviceHeaders($b, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem')])
        ->assertStatus(404);
});

it('rejects a pem certificate upload missing certificate and private_key', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pem'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rejects a pkcs12 certificate upload missing pkcs12 and passphrase', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pkcs12'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});
