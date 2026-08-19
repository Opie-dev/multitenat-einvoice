<?php

use App\Models\ReferenceCode;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

it('imports reference JSON files idempotently', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $first = ReferenceCode::count();
    expect($first)->toBeGreaterThan(50);
    expect(ReferenceCode::where('set', 'tax_types')->where('code', '01')->value('description'))->toBe('Sales Tax');
    expect(ReferenceCode::where('set', 'document_types')->where('code', '11')->value('description'))->toBe('Self-billed Invoice');

    Artisan::call('einvoice:refresh-reference-data');
    expect(ReferenceCode::count())->toBe($first);
});

it('serves a reference set with an ETag and 304', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $tenant = Tenant::factory()->create();
    $res = $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/reference/state_codes')->assertOk()
        ->assertJsonPath('data.0.code', '00')->assertJsonStructure(['meta' => ['version', 'count']]);
    $etag = $res->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->withHeaders(apiKeyHeaders($tenant) + ['If-None-Match' => $etag])->getJson('/v1/reference/state_codes')->assertStatus(304);
});

it('works for service tokens without a tenant header', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $this->withHeader('Authorization', 'Bearer '.serviceToken())->getJson('/v1/reference/tax_types')->assertOk();
});

it('returns 404 for unknown sets', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/reference/nope')->assertStatus(404);
});
