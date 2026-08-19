<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Events\IssuerActivated;
use App\Jobs\ReleaseHeldDocuments;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Services\Issuers\IssuerActivator;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->authorized()->create(['certificate_valid_until' => now()->addYear()]);
});

it('dispatches IssuerActivated and queues the release job when an issuer becomes active', function () {
    Queue::fake();
    (new IssuerActivator)->apply($this->issuer);
    expect($this->issuer->status)->toBe(IssuerStatus::Active);
    Queue::assertPushed(ReleaseHeldDocuments::class, fn ($job) => $job->issuerId === $this->issuer->id && $job->tenantId === $this->tenant->id);
});

it('moves releasable held documents to queued and leaves the others alone', function () {
    $a = Document::factory()->for($this->issuer)->held(HeldReason::IssuerNotActive)->create();
    $b = Document::factory()->for($this->issuer)->held(HeldReason::CertificateExpired)->create();
    $c = Document::factory()->for($this->issuer)->held(HeldReason::EinvoiceNotRequired)->create();
    $other = Document::factory()->for(Issuer::factory()->for($this->tenant)->create())->held(HeldReason::IssuerNotActive)->create();

    $job = new ReleaseHeldDocuments($this->issuer->id);
    app(TenantContext::class)->clear();
    dispatch_sync($job);

    // BindTenantContext clears the context again once the job finishes, and
    // DocumentEvent's tenant scope fails closed (zero rows) with no context
    // bound — rebind so the assertion below can read the event it created.
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);

    expect($a->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($b->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($c->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($other->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($a->events()->get()->last()->reason)->toBe('issuer_activated');
});

it('runs end-to-end through the real listener when the queue is sync', function () {
    Event::fake([IssuerActivated::class]);
    (new IssuerActivator)->apply($this->issuer);
    Event::assertDispatched(IssuerActivated::class);
});
