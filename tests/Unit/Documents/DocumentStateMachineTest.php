<?php

use App\Domain\Documents\CancellationWindowClosed;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\InvalidTransition;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->create();
    $this->sm = new DocumentStateMachine;
});

dataset('allowed', [
    ['draft', 'validated'], ['validated', 'queued'], ['validated', 'held'], ['validated', 'awaiting_consolidation'],
    ['held', 'queued'], ['queued', 'submitted'], ['queued', 'held'], ['submitted', 'valid'], ['submitted', 'invalid'],
    ['invalid', 'queued'], ['valid', 'rejected'], ['awaiting_consolidation', 'consolidated'], ['awaiting_consolidation', 'queued'],
]);
dataset('forbidden', [
    ['draft', 'queued'], ['queued', 'valid'], ['valid', 'queued'], ['cancelled', 'queued'], ['consolidated', 'queued'], ['held', 'valid'],
]);

it('allows documented transitions', function (string $from, string $to) {
    expect($this->sm->canTransition(DocumentStatus::from($from), DocumentStatus::from($to)))->toBeTrue();
})->with('allowed');

it('forbids everything else', function (string $from, string $to) {
    expect($this->sm->canTransition(DocumentStatus::from($from), DocumentStatus::from($to)))->toBeFalse();
    $doc = Document::factory()->for($this->issuer)->create(['status' => $from]);
    expect(fn () => $this->sm->transition($doc, DocumentStatus::from($to)))->toThrow(InvalidTransition::class);
})->with('forbidden');

it('records an event, sets timestamps and held reason, and dispatches DocumentTransitioned', function () {
    Event::fake([DocumentTransitioned::class]);
    $doc = Document::factory()->for($this->issuer)->create(['status' => 'draft']);
    $this->sm->transition($doc, DocumentStatus::Validated);
    $this->sm->transition($doc, DocumentStatus::Held, HeldReason::IssuerNotActive->value);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($doc->held_reason)->toBe(HeldReason::IssuerNotActive)
        ->and($doc->validated_at)->not->toBeNull()
        ->and($doc->events()->count())->toBe(2)
        ->and($doc->events()->get()->last()->reason)->toBe('issuer_not_active');
    $this->sm->transition($doc, DocumentStatus::Queued, 'issuer_activated');
    expect($doc->refresh()->held_reason)->toBeNull();
    Event::assertDispatched(DocumentTransitioned::class, 3);
});

it('enforces the 72h cancellation window', function () {
    $doc = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHours(73)]);
    expect(fn () => $this->sm->transition($doc, DocumentStatus::Cancelled, 'wrong buyer'))->toThrow(CancellationWindowClosed::class);
    $fresh = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHour()]);
    $this->sm->transition($fresh, DocumentStatus::Cancelled, 'wrong buyer');
    expect($fresh->refresh()->status)->toBe(DocumentStatus::Cancelled)->and($fresh->cancel_reason)->toBe('wrong buyer')->and($fresh->cancelled_at)->not->toBeNull();
});
