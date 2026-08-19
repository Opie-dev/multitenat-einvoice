<?php

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * Documents get their tenant_id from the bound TenantContext (BelongsToTenant),
 * not from a factory ->for() relation, so bind the context around the create and
 * clear it again afterwards to avoid leaking it into the request under test.
 */
function documentFor(Tenant $t, Environment $environment = Environment::Sandbox): Document
{
    $issuer = Issuer::factory()->for($t)->create(['environment' => $environment]);
    app(TenantContext::class)->bind($t, null, $environment);
    $document = Document::factory()->for($issuer)->queued()->create(['environment' => $environment]);
    app(TenantContext::class)->clear();

    return $document;
}

/**
 * Every tenant-owned resource route must 404 for a different tenant.
 * When you add a tenant-scoped resource route in a later plan, add a row here.
 */
dataset('cross_tenant_routes', function () {
    return [
        'issuer show' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'GET', '/v1/issuers/{id}'],
        'issuer update' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'PATCH', '/v1/issuers/{id}'],
        'issuer credentials' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox, 'lhdn_mode' => 'own_credentials']), 'PUT', '/v1/issuers/{id}/credentials'],
        'issuer certificate' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'PUT', '/v1/issuers/{id}/certificate'],
        'issuer verify-tin' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'POST', '/v1/issuers/{id}/verify-tin'],
        'issuer authorize' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'POST', '/v1/issuers/{id}/authorize'],
        'buyer show' => [fn (Tenant $t) => Buyer::factory()->for($t)->create(), 'GET', '/v1/buyers/{id}'],
        'buyer update' => [fn (Tenant $t) => Buyer::factory()->for($t)->create(), 'PATCH', '/v1/buyers/{id}'],
        'api key revoke' => [fn (Tenant $t) => ApiKey::generate($t, 'k', Environment::Sandbox, ['read'])['key'], 'DELETE', '/v1/api-keys/{id}'],
        'document show' => [fn (Tenant $t) => documentFor($t), 'GET', '/v1/documents/{id}'],
        'document events' => [fn (Tenant $t) => documentFor($t), 'GET', '/v1/documents/{id}/events'],
        'document submit' => [fn (Tenant $t) => documentFor($t), 'POST', '/v1/documents/{id}/submit'],
    ];
});

it('returns 404 for cross-tenant access', function (Closure $make, string $method, string $path) {
    $owner = Tenant::factory()->create();
    $intruder = Tenant::factory()->create();
    $resource = $make($owner);
    $url = str_replace('{id}', $resource->getKey(), $path);

    $this->withHeaders(serviceHeaders($intruder, 'sandbox'))
        ->json($method, $url, ['name' => 'x', 'client_id' => 'a', 'client_secret' => 'b', 'format' => 'pem', 'certificate' => 'x', 'private_key' => 'y'])
        ->assertStatus(404);
})->with('cross_tenant_routes');

/**
 * A credential bound to one environment must never reach the other one, even
 * within its own tenant. Same rule as cross-tenant access: 404, never 403.
 */
dataset('cross_environment_routes', function () {
    return [
        'issuer show' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Production]), 'GET', '/v1/issuers/{id}'],
        'issuer verify-tin (prod issuer, test key)' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Production]), 'POST', '/v1/issuers/{id}/verify-tin'],
        'api key revoke' => [fn (Tenant $t) => ApiKey::generate($t, 'k', Environment::Production, ['read'])['key'], 'DELETE', '/v1/api-keys/{id}'],
        'document show (prod doc, test key)' => [fn (Tenant $t) => documentFor($t, Environment::Production), 'GET', '/v1/documents/{id}'],
        'document events (prod doc, test key)' => [fn (Tenant $t) => documentFor($t, Environment::Production), 'GET', '/v1/documents/{id}/events'],
        'document submit (prod doc, test key)' => [fn (Tenant $t) => documentFor($t, Environment::Production), 'POST', '/v1/documents/{id}/submit'],
    ];
});

it('returns 404 for cross-environment access by an api key', function (Closure $make, string $method, string $path) {
    $tenant = Tenant::factory()->create();
    $resource = $make($tenant);
    $url = str_replace('{id}', $resource->getKey(), $path);

    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox'))
        ->json($method, $url)
        ->assertStatus(404);
})->with('cross_environment_routes');

it('lists are empty for another tenant', function () {
    $owner = Tenant::factory()->create();
    $intruder = Tenant::factory()->create();
    Issuer::factory()->for($owner)->create(['environment' => Environment::Sandbox]);
    Buyer::factory()->for($owner)->create();
    ApiKey::generate($owner, 'k', Environment::Sandbox, ['read']);
    documentFor($owner);

    foreach (['/v1/issuers', '/v1/buyers', '/v1/api-keys', '/v1/documents'] as $path) {
        $this->withHeaders(serviceHeaders($intruder, 'sandbox'))->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    }
});
