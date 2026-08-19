<?php

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->sm = new DocumentStateMachine;
});

it('requires a typed HeldReason when holding and records it', function () {
    $doc = Document::factory()->for($this->issuer)->create(['status' => 'validated']);
    $this->sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);
    expect($doc->refresh()->held_reason)->toBe(HeldReason::LhdnUnavailable)
        ->and($doc->events()->get()->last()->reason)->toBe('lhdn_unavailable');
    expect(fn () => $this->sm->transition(Document::factory()->for($this->issuer)->create(['status' => 'validated']), DocumentStatus::Held))
        ->toThrow(InvalidArgumentException::class);
});

it('allows held → held re-hold and queued → invalid', function () {
    $doc = Document::factory()->for($this->issuer)->held(HeldReason::IssuerNotActive)->create();
    $this->sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);
    expect($doc->refresh()->held_reason)->toBe(HeldReason::LhdnUnavailable);
    $q = Document::factory()->for($this->issuer)->queued()->create();
    $this->sm->transition($q, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => [['code' => 'X', 'message' => 'bad']]]);
    expect($q->refresh()->status)->toBe(DocumentStatus::Invalid)->and($q->lhdn_status_at)->not->toBeNull();
});

it('stores submission attempts tenant-scoped with redacted payloads', function () {
    $a = SubmissionAttempt::factory()->for($this->issuer)->create(['operation' => 'token', 'request' => ['client_id' => 'abc***'], 'http_status' => 200]);
    expect(SubmissionAttempt::count())->toBe(1)->and($a->tenant_id)->toBe($this->tenant->id);
    app(TenantContext::class)->bind(Tenant::factory()->create(), null, Environment::Sandbox);
    expect(SubmissionAttempt::count())->toBe(0);
});

it('has the new document columns', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['lhdn_internal_id' => 'X1', 'ubl_json' => '{"a":1}', 'signed_payload_hash' => str_repeat('a', 64), 'submission_attempts_count' => 2]);
    expect($doc->refresh()->ubl_json)->toBe('{"a":1}')->and($doc->submission_attempts_count)->toBe(2)->and($doc->lhdn_internal_id)->toBe('X1');
});
