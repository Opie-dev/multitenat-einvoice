<?php

use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\InvalidTransition;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\RefreshDocumentStatus;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
});

function validDoc(Issuer $issuer, array $attrs = []): Document
{
    return Document::factory()->for($issuer)->valid()->create($attrs);
}

it('applies buyer rejection and LHDN-side cancellation to valid documents', function () {
    $a = validDoc($this->issuer);
    $b = validDoc($this->issuer, ['lhdn_status_at' => now()->subHours(100)]); // outside our cancel window
    fakeLhdn()->registerDocument((string) $a->lhdn_uuid, 'Valid');
    fakeLhdn()->registerDocument((string) $b->lhdn_uuid, 'Valid');
    fakeLhdn()->markRejected((string) $a->lhdn_uuid);
    fakeLhdn()->markCancelled((string) $b->lhdn_uuid);
    dispatch_sync(new RefreshDocumentStatus($a->id));
    dispatch_sync(new RefreshDocumentStatus($b->id));
    expect($a->refresh()->status)->toBe(DocumentStatus::Rejected)
        ->and($a->lhdn_refreshed_at)->not->toBeNull()
        ->and($a->events()->get()->last()->reason)->toBe('buyer_rejected')
        ->and($b->refresh()->status)->toBe(DocumentStatus::Cancelled); // window bypassed — LHDN is authoritative
});

it('stamps lhdn_refreshed_at without transitioning when LHDN still says valid, and skips non-valid docs', function () {
    $doc = validDoc($this->issuer);
    fakeLhdn()->registerDocument((string) $doc->lhdn_uuid, 'Valid');
    dispatch_sync(new RefreshDocumentStatus($doc->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid)->and($doc->lhdn_refreshed_at)->not->toBeNull();

    $queued = Document::factory()->for($this->issuer)->queued()->create();
    dispatch_sync(new RefreshDocumentStatus($queued->id));
    expect($queued->refresh()->lhdn_refreshed_at)->toBeNull();
});

it('sweeps stale valid documents into refresh jobs', function () {
    Queue::fake([RefreshDocumentStatus::class]);
    $stale = validDoc($this->issuer, ['lhdn_refreshed_at' => now()->subHours(7)]);
    $fresh = validDoc($this->issuer, ['lhdn_refreshed_at' => now()->subHour()]);
    $old = validDoc($this->issuer, ['lhdn_status_at' => now()->subDays(10)]);
    app(TenantContext::class)->clear();
    Artisan::call('einvoice:lhdn-dispatch');
    Queue::assertPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $stale->id);
    Queue::assertNotPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $fresh->id);
    Queue::assertNotPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $old->id);
});

it('applyLhdnVerdict refuses pairs outside its whitelist and no-ops on same status', function () {
    $sm = app(DocumentStateMachine::class);
    $doc = validDoc($this->issuer);
    expect($sm->applyLhdnVerdict($doc, DocumentStatus::Valid))->toBeNull();
    expect(fn () => $sm->applyLhdnVerdict($doc, DocumentStatus::Queued))->toThrow(InvalidTransition::class);
});
