<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->calls = 0;
    Route::middleware(['api', 'auth.api', 'tenant', 'idempotency'])->prefix('v1')->post('/_test/idem', function () {
        $this->calls++;

        return response()->json(['n' => $this->calls], 201);
    });
    Route::middleware(['api', 'auth.api', 'tenant', 'idempotency'])->prefix('v1')->post('/_test/idem-fail', fn () => response()->json(['bad' => true], 422));
    $this->tenant = Tenant::factory()->create();
});

it('replays a cached 2xx response for the same key and body', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k1'];
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertJson(['n' => 1])->assertHeaderMissing('Idempotent-Replay');
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertJson(['n' => 1])->assertHeader('Idempotent-Replay', 'true');
    expect($this->calls)->toBe(1);
});

it('rejects the same key with a different body', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k2'];
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated();
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 2])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
});

it('does not cache non-2xx responses and scopes keys per tenant', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k3'];
    $this->withHeaders($h)->postJson('/v1/_test/idem-fail', [])->assertStatus(422);
    $this->withHeaders($h)->postJson('/v1/_test/idem-fail', [])->assertStatus(422)->assertHeaderMissing('Idempotent-Replay');

    $other = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($this->tenant) + ['Idempotency-Key' => 'shared'])->postJson('/v1/_test/idem', ['a' => 1])->assertCreated();
    $this->withHeaders(serviceHeaders($other) + ['Idempotency-Key' => 'shared'])->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertHeaderMissing('Idempotent-Replay');
});

it('rejects malformed keys', function () {
    $this->withHeaders(serviceHeaders($this->tenant) + ['Idempotency-Key' => str_repeat('x', 129)])->postJson('/v1/_test/idem', [])
        ->assertStatus(400)->assertJsonPath('code', 'invalid_idempotency_key');
});

it('scopes keys per environment', function () {
    $key = ['Idempotency-Key' => 'same-key'];
    $this->withHeaders(serviceHeaders($this->tenant, 'sandbox') + $key)->postJson('/v1/_test/idem', ['a' => 1])
        ->assertCreated()->assertHeaderMissing('Idempotent-Replay');
    $this->withHeaders(serviceHeaders($this->tenant, 'production') + $key)->postJson('/v1/_test/idem', ['a' => 1])
        ->assertCreated()->assertHeaderMissing('Idempotent-Replay');
    expect($this->calls)->toBe(2);
});
