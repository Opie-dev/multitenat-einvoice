<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores decimals as strings, casts enums, and scopes by tenant + environment', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    $doc = Document::factory()->for($issuer)->create(['total_payable' => '12.50', 'status' => DocumentStatus::Queued]);
    DocumentLine::factory()->for($doc)->create(['position' => 1]);

    $fresh = Document::forCurrentEnvironment()->with('lines')->find($doc->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->total_payable)->toBe('12.50')
        ->and($fresh->status)->toBe(DocumentStatus::Queued)
        ->and($fresh->lines)->toHaveCount(1)
        ->and($fresh->issuer->is($issuer))->toBeTrue();

    app(TenantContext::class)->bind($tenant, null, Environment::Production);
    expect(Document::forCurrentEnvironment()->find($doc->id))->toBeNull();
});

it('enforces the natural idempotency key', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    Document::factory()->for($issuer)->create(['source_system' => 'catalog', 'source_ref' => 'o1', 'type' => 'invoice']);
    Document::factory()->for($issuer)->create(['source_system' => 'catalog', 'source_ref' => 'o1', 'type' => 'invoice']);
})->throws(UniqueConstraintViolationException::class);

it('knows the cancellation window', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    $doc = Document::factory()->for($issuer)->valid()->create(['lhdn_status_at' => now()->subHours(71)]);
    expect($doc->isCancellable())->toBeTrue();
    $doc->lhdn_status_at = now()->subHours(73);
    expect($doc->isCancellable())->toBeFalse();
    $held = Document::factory()->for($issuer)->held(HeldReason::IssuerNotActive)->create();
    expect($held->isCancellable())->toBeFalse()->and($held->held_reason)->toBe(HeldReason::IssuerNotActive);
});
