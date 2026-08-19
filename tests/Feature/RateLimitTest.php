<?php

use App\Models\Tenant;

it('throttles per credential and answers with problem+json', function () {
    config(['einvoice.rate_limit_per_minute' => 2]);
    $tenant = Tenant::factory()->create();
    $headers = serviceHeaders($tenant);

    $this->withHeaders($headers)->getJson('/v1/me')->assertOk();
    $this->withHeaders($headers)->getJson('/v1/me')->assertOk();

    $res = $this->withHeaders($headers)->getJson('/v1/me')->assertStatus(429);
    expect($res->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($res->headers->get('Retry-After'))->not->toBeNull();
    $res->assertJsonPath('title', 'Too Many Requests')->assertJsonPath('status', 429);
});

it('does not throttle a different credential', function () {
    config(['einvoice.rate_limit_per_minute' => 2]);
    $tenant = Tenant::factory()->create();
    $first = serviceHeaders($tenant);

    $this->withHeaders($first)->getJson('/v1/me')->assertOk();
    $this->withHeaders($first)->getJson('/v1/me')->assertOk();
    $this->withHeaders($first)->getJson('/v1/me')->assertStatus(429);

    $this->withHeaders(serviceHeaders($tenant))->getJson('/v1/me')->assertOk();
});
