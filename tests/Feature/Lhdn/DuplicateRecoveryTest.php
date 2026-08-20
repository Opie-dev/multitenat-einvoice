<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\PollSubmission;
use App\Jobs\SubmitDocuments;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
});

it('adopts the uuid from a prior submit attempt when LHDN rejects a duplicate codeNumber', function () {
    Queue::fake([PollSubmission::class]);
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-1']);
    SubmissionAttempt::factory()->for($this->issuer)->create([
        'operation' => 'submit', 'submission_uid' => 'SUB-LOST',
        'response' => ['submissionUid' => 'SUB-LOST', 'acceptedDocuments' => [['uuid' => 'UUID-LOST', 'invoiceCodeNumber' => 'DOC-DUP-1']]],
    ]);
    fakeLhdn()->rejectDocument('DOC-DUP-1', 'DUPLICATE_SUBMISSION', 'Duplicated submission');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Submitted)
        ->and($doc->lhdn_uuid)->toBe('UUID-LOST')
        ->and($doc->lhdn_submission_uid)->toBe('SUB-LOST')
        ->and($doc->events()->get()->last()->reason)->toBe('duplicate_recovered');
    Queue::assertPushed(PollSubmission::class, fn ($j) => $j->submissionUid === 'SUB-LOST');
});

it('falls back to invalid when no prior attempt carries the codeNumber', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-2']);
    fakeLhdn()->rejectDocument('DOC-DUP-2', 'DUPLICATE_SUBMISSION', 'Duplicated submission');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc->lhdn_errors[0]['code'])->toBe('DUPLICATE_SUBMISSION');
});

it('does not treat ordinary rejections as duplicates', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-3']);
    fakeLhdn()->rejectDocument('DOC-DUP-3', 'CF321', 'Schema error');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc->lhdn_errors[0]['code'])->toBe('CF321');
});
