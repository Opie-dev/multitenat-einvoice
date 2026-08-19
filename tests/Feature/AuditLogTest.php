<?php

use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;

it('records api key creation and revocation with actor and tenant', function () {
    $tenant = Tenant::factory()->create();
    $id = $this->withHeaders(serviceHeaders($tenant) + ['X-Request-Id' => 'req-1'])
        ->postJson('/v1/api-keys', ['name' => 'k', 'environment' => 'sandbox', 'abilities' => ['read']])
        ->json('data.id');
    $this->withHeaders(serviceHeaders($tenant))->deleteJson("/v1/api-keys/{$id}")->assertNoContent();

    $logs = AuditLog::where('tenant_id', $tenant->id)->orderBy('created_at')->get();
    expect($logs->pluck('action')->all())->toBe(['api_key.created', 'api_key.revoked'])
        ->and($logs[0]->actor_type)->toBe('service')
        ->and($logs[0]->subject_id)->toBe($id)
        ->and($logs[0]->request_id)->toBe('req-1');
});

it('records issuer updates with a diff but never secret values', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['name' => 'Old', 'lhdn_mode' => 'own_credentials']);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->patchJson("/v1/issuers/{$issuer->id}", ['name' => 'New'])->assertOk();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'topsecret'])->assertOk();

    $update = AuditLog::where('action', 'issuer.updated')->first();
    expect($update->changes)->toBe(['name' => ['from' => 'Old', 'to' => 'New']]);
    $cred = AuditLog::where('action', 'issuer.credentials_updated')->first();
    expect(json_encode($cred->toArray()))->not->toContain('topsecret');
});
