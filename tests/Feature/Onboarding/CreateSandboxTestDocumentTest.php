<?php

use App\Actions\Documents\CreateSandboxTestDocument;
use App\Domain\Onboarding\IssuerOnboardingState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

beforeEach(function () use ($certs) {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Sandbox]);
    $this->issuer->secret()->create([
        'signing_certificate' => $certs('test-cert.pem'),
        'signing_key' => $certs('test-key.pem'),
        'cert_not_after' => now()->addYears(5),
    ]);
});

it('creates and submits the fixed sandbox sample invoice through the real CreateDocument pipeline', function () {
    $doc = app(CreateSandboxTestDocument::class)->handle($this->issuer);

    expect($doc->type)->toBe(DocumentType::Invoice)
        ->and($doc->currency)->toBe('MYR')
        ->and($doc->environment)->toBe(Environment::Sandbox)
        ->and($doc->issuer_id)->toBe($this->issuer->id)
        ->and($doc->source_system)->toBe('dashboard-test')
        ->and($doc->source_ref)->toStartWith('dashtest-')
        ->and($doc->consolidate)->toBeFalse()
        ->and($doc->buyer_snapshot['name'])->toBe('Sandbox Test Buyer')
        ->and($doc->buyer_snapshot['tin'])->toBe('EI00000000010')
        ->and($doc->lines)->toHaveCount(1);

    $line = $doc->lines->first();
    expect($line->classification_code)->toBe('022')
        ->and($line->description)->toBe('Dashboard sandbox test')
        ->and((float) $line->quantity)->toBe(1.0)
        ->and((float) $line->unit_price)->toBe(10.0)
        ->and($line->tax_type)->toBe('E');

    // Sync queue settles the fake pipeline inline, so this is already the final state.
    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid);
});

it('always runs against sandbox even when the caller session is bound to production, and restores that context afterwards', function () {
    $callerTenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($callerTenant, null, Environment::Production);

    $doc = app(CreateSandboxTestDocument::class)->handle($this->issuer);

    expect($doc->environment)->toBe(Environment::Sandbox)
        ->and(app(TenantContext::class)->tenant()->id)->toBe($callerTenant->id)
        ->and(app(TenantContext::class)->environment())->toBe(Environment::Production);
});

it('allows repeat attempts by minting a fresh source_ref each time', function () {
    $first = app(CreateSandboxTestDocument::class)->handle($this->issuer);
    $second = app(CreateSandboxTestDocument::class)->handle($this->issuer);

    expect($first->id)->not->toBe($second->id)
        ->and($first->source_ref)->not->toBe($second->source_ref);
});

it('marks the step done once the sandbox test document is valid', function () {
    app(CreateSandboxTestDocument::class)->handle($this->issuer);

    $step = collect(
        IssuerOnboardingState::for($this->issuer, Environment::Sandbox)->steps()
    )->firstWhere('key', 'sandbox_test');

    expect($step->done)->toBeTrue();
});
