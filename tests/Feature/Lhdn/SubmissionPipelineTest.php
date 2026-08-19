<?php

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SubmitDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Resources\DocumentData;
use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Jobs\PollSubmission;
use App\Jobs\PrepareDocument;
use App\Jobs\SubmitDocuments;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnException;
use App\Lhdn\Signing\DocumentSigner;
use App\Lhdn\Signing\SigningMaterial;
use App\Lhdn\Ubl\UblDocumentBuilder;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

$certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

/** @param array<string, mixed> $overrides */
function pipelineDoc(Issuer $issuer, array $overrides = []): Document
{
    $payload = array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '10.00', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(4))],
    ], $overrides);

    return app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document->refresh();
}

/**
 * Sign a document with the test fixtures exactly the way PrepareDocument will, so
 * a test can set a byte cap relative to the sizes this document actually produces.
 *
 * @return array{int, int} raw JSON bytes, then base64-encoded (wire) bytes
 */
function signedSizes(Document $document): array
{
    $material = new SigningMaterial(
        (string) file_get_contents(base_path('tests/Fixtures/certs/test-cert.pem')),
        (string) file_get_contents(base_path('tests/Fixtures/certs/test-key.pem')),
    );
    $signed = app(DocumentSigner::class)->sign(app(UblDocumentBuilder::class)->build($document), $material);

    return [strlen($signed->json), strlen(base64_encode($signed->json))];
}

function pipelineSubmit(Issuer $issuer): SubmitDocuments
{
    $job = new SubmitDocuments($issuer->id);
    $job->handle(app(LhdnClientFactory::class), app(DocumentStateMachine::class));

    return $job;
}

function pipelinePoll(Issuer $issuer, string $uid, int $attempt = 0): void
{
    (new PollSubmission($issuer->id, $uid, $attempt))->handle(app(LhdnClientFactory::class), app(DocumentStateMachine::class));
}

beforeEach(function () use ($certs) {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->issuer->secret()->create(['signing_certificate' => $certs('test-cert.pem'), 'signing_key' => $certs('test-key.pem'), 'cert_not_after' => now()->addYears(5)]);
});

it('runs queued -> prepared -> submitted -> valid end-to-end on the sync queue', function () {
    $doc = pipelineDoc($this->issuer); // sync queue: listener -> PrepareDocument -> SubmitDocuments -> PollSubmission run inline
    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Valid)
        ->and($doc->ubl_json)->not->toBeNull()
        ->and($doc->signed_payload_hash)->toHaveLength(64)
        ->and($doc->lhdn_uuid)->not->toBeNull()
        ->and($doc->lhdn_long_id)->not->toBeNull()
        ->and($doc->lhdn_submission_uid)->toStartWith('SUB-')
        ->and($doc->submission_attempts_count)->toBe(1)
        ->and($doc->events()->pluck('to_status')->map->value->all())->toBe(['validated', 'queued', 'submitted', 'valid']);
    $ops = collect(fakeLhdn()->calls())->pluck('operation')->all();
    expect($ops)->toContain('submit', 'get_submission');
    expect(SubmissionAttempt::count())->toBe(0); // fake client does not record; HttpLhdnClient does (Task 3)
});

it('marks documents invalid when LHDN rejects them at submission or after validation', function () {
    fakeLhdn()->pollsUntilFinal(0);
    // Reject by internal id: we need the document id before submit, so create with submit=false first.
    $doc = pipelineDoc($this->issuer, ['submit' => false]);
    fakeLhdn()->rejectDocument((string) $doc->lhdn_internal_id, 'CF321', 'Schema error');
    app(SubmitDocument::class)->handle($doc);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)
        ->and($doc->lhdn_errors[0]['code'])->toBe('CF321')
        ->and($doc->events()->pluck('to_status')->map->value->all())->toBe(['validated', 'queued', 'invalid']);

    Queue::fake([PollSubmission::class]);
    $doc2 = pipelineDoc($this->issuer);
    expect($doc2->refresh()->status)->toBe(DocumentStatus::Submitted);
    fakeLhdn()->markInvalid((string) $doc2->lhdn_uuid, [['code' => 'DS302', 'message' => 'tax mismatch']]);
    pipelinePoll($this->issuer, (string) $doc2->lhdn_submission_uid);
    expect($doc2->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc2->lhdn_errors[0]['code'])->toBe('DS302');
});

it('holds documents when the issuer is not ready and when LHDN is down for too long', function () use ($certs) {
    $this->issuer->secret()->delete();
    $doc = pipelineDoc($this->issuer->refresh());
    expect($doc->refresh()->status)->toBe(DocumentStatus::Held)->and($doc->held_reason)->toBe(HeldReason::CertificateExpired);

    $this->issuer->secret()->create(['signing_certificate' => $certs('test-cert.pem'), 'signing_key' => $certs('test-key.pem')]);
    config(['lhdn.submission.max_attempts' => 2, 'lhdn.submission.retry_backoff_seconds' => [1, 1]]);
    Queue::fake([SubmitDocuments::class, PollSubmission::class]);
    $d2 = pipelineDoc($this->issuer->refresh()); // prepared synchronously; SubmitDocuments faked
    expect($d2->refresh()->status)->toBe(DocumentStatus::Queued)->and($d2->ubl_json)->not->toBeNull();

    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'submit');
    pipelineSubmit($this->issuer);
    expect($d2->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($d2->submission_attempts_count)->toBe(1)
        ->and($d2->next_submission_at)->not->toBeNull()
        ->and($d2->last_submission_error['kind'])->toBe('transient');
    Queue::assertPushed(SubmitDocuments::class);

    $d2->forceFill(['next_submission_at' => now()->subSecond()])->save();
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'submit');
    pipelineSubmit($this->issuer);
    expect($d2->refresh()->status)->toBe(DocumentStatus::Held)->and($d2->held_reason)->toBe(HeldReason::LhdnUnavailable);

    $d3 = pipelineDoc($this->issuer);
    fakeLhdn()->failNextWith(LhdnException::auth('bad creds', 401), 'submit');
    pipelineSubmit($this->issuer);
    expect($d3->refresh()->status)->toBe(DocumentStatus::Held)->and($d3->held_reason)->toBe(HeldReason::LhdnCredentialsInvalid);
});

it('keeps polling with backoff while LHDN is in progress, and the scheduler sweep re-dispatches stragglers', function () {
    Queue::fake([PollSubmission::class]);
    fakeLhdn()->pollsUntilFinal(1);
    $doc = pipelineDoc($this->issuer);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, 0);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);
    Queue::assertPushed(PollSubmission::class, fn (PollSubmission $j) => $j->attempt === 1);
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, 1);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid);

    Queue::fake([SubmitDocuments::class, PollSubmission::class, PrepareDocument::class]);
    Document::factory()->for($this->issuer)->queued()->create([
        'ubl_json' => '{}', 'lhdn_internal_id' => 'STRAG1',
    ]);
    Document::factory()->for($this->issuer)->create([
        'status' => 'submitted', 'lhdn_submission_uid' => 'SUB-OLD', 'lhdn_uuid' => 'U-OLD',
        'submitted_at' => now()->subMinutes(10), 'lhdn_internal_id' => 'OLD1',
    ]);
    $unprepared = Document::factory()->for($this->issuer)->queued()->create([
        'lhdn_internal_id' => 'UNP1', 'created_at' => now()->subMinutes(5),
    ]);
    Artisan::call('einvoice:lhdn-dispatch');
    // The sweep walks every tenant itself, but hands the caller's context back.
    expect(app(TenantContext::class)->tenant()->id)->toBe($this->tenant->id);
    Queue::assertPushed(SubmitDocuments::class, fn (SubmitDocuments $j) => $j->issuerId === $this->issuer->id);
    Queue::assertPushed(PollSubmission::class, fn (PollSubmission $j) => $j->submissionUid === 'SUB-OLD');
    Queue::assertPushed(PrepareDocument::class, fn (PrepareDocument $j) => $j->documentId === $unprepared->id);
});

it('exposes the LHDN validation url once valid', function () {
    config(['lhdn.environments.sandbox.portal_base' => 'https://preprod.myinvois.hasil.gov.my']);
    $doc = pipelineDoc($this->issuer)->refresh();
    $data = DocumentData::fromModel($doc)->toArray();
    expect($data['lhdn']['validation_url'])->toBe("https://preprod.myinvois.hasil.gov.my/{$doc->lhdn_uuid}/share/{$doc->lhdn_long_id}");
});

it('measures the per-document limit against the encoded payload, not the raw JSON', function () {
    Queue::fake([SubmitDocuments::class]);

    // A cap strictly between the two sizes is the whole point of this test: sizing
    // on the raw JSON would let this document through, wire sizing rejects it.
    $tooBig = pipelineDoc($this->issuer, ['submit' => false]);
    [$raw, $wire] = signedSizes($tooBig);
    expect($wire)->toBeGreaterThan($raw);
    config(['lhdn.submission.max_document_bytes' => intdiv($raw + $wire, 2)]);
    app(DocumentStateMachine::class)->transition($tooBig, DocumentStatus::Queued, 'manual_submit');
    expect($tooBig->refresh()->status)->toBe(DocumentStatus::Invalid)
        ->and($tooBig->ubl_json)->toBeNull()
        ->and($tooBig->lhdn_errors[0]['code'])->toBe('DOC_TOO_LARGE')
        ->and($tooBig->events()->get()->last()->reason)->toBe('document_too_large');
    Queue::assertNotPushed(SubmitDocuments::class);

    // One byte of headroom above the encoded size, and an identical document sails through.
    $fits = pipelineDoc($this->issuer, ['submit' => false]);
    [, $fitsWire] = signedSizes($fits);
    config(['lhdn.submission.max_document_bytes' => $fitsWire + 1]);
    app(DocumentStateMachine::class)->transition($fits, DocumentStatus::Queued, 'manual_submit');
    expect($fits->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($fits->ubl_json)->not->toBeNull()
        ->and($fits->lhdn_errors)->toBeNull();
    Queue::assertPushed(SubmitDocuments::class);
});

it('marks the whole batch invalid when LHDN rejects the submission outright', function () {
    Queue::fake([SubmitDocuments::class, PollSubmission::class]);
    $a = pipelineDoc($this->issuer);
    $b = pipelineDoc($this->issuer);
    fakeLhdn()->failNextWith(LhdnException::terminal('Invalid payload schema', 400), 'submit');
    pipelineSubmit($this->issuer);

    foreach ([$a, $b] as $doc) {
        expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)
            ->and($doc->lhdn_errors[0]['code'])->toBe('LHDN_400')
            ->and($doc->lhdn_errors[0]['message'])->toBe('Invalid payload schema')
            ->and($doc->last_submission_error['kind'])->toBe('terminal')
            ->and($doc->submission_attempts_count)->toBe(1)
            ->and($doc->events()->get()->last()->reason)->toBe('rejected_at_submission');
    }
});

it('holds documents for an issuer that is no longer active', function () {
    $doc = pipelineDoc($this->issuer, ['submit' => false]);
    $this->issuer->forceFill(['status' => IssuerStatus::Draft])->save();
    // Queued the way ReleaseHeldDocuments does it, bypassing the action's own
    // issuer check, so PrepareDocument is the thing under test here.
    app(DocumentStateMachine::class)->transition($doc, DocumentStatus::Queued, 'issuer_activated');

    expect($doc->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($doc->held_reason)->toBe(HeldReason::IssuerNotActive)
        ->and($doc->ubl_json)->toBeNull();
});

it('reschedules instead of invalidating when the submission read itself fails', function () {
    Queue::fake([PollSubmission::class]);
    $doc = pipelineDoc($this->issuer);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);

    // A terminal status on getSubmission is a statement about our read, not about
    // the invoice: it must never fabricate LHDN_4xx errors onto the documents.
    fakeLhdn()->failNextWith(LhdnException::terminal('nf', 404), 'get_submission');
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, 0);

    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted)->and($doc->lhdn_errors)->toBeNull();
    Queue::assertPushed(PollSubmission::class, fn (PollSubmission $j) => $j->attempt === 1);

    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'get_submission');
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, 1);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted)->and($doc->lhdn_errors)->toBeNull();
    Queue::assertPushed(PollSubmission::class, fn (PollSubmission $j) => $j->attempt === 2);
});

it('asks LHDN about each document directly once the poll curve is exhausted', function () {
    Queue::fake([PollSubmission::class]);
    $doc = pipelineDoc($this->issuer);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);

    // Flip the fake's own state to Valid without the job seeing it, so the
    // per-document getDocument() call is the only thing that can settle it.
    fakeLhdn()->getSubmission($this->issuer, (string) $doc->lhdn_submission_uid);

    $last = count((array) config('lhdn.poll.backoff_seconds')) - 1;
    fakeLhdn()->failNextWith(LhdnException::terminal('nf', 404), 'get_submission');
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, $last);

    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid)->and($doc->lhdn_long_id)->not->toBeNull();
    Queue::assertNotPushed(PollSubmission::class, fn (PollSubmission $j) => $j->attempt > $last);
});

it('leaves a document submitted when the per-document check also fails', function () {
    Queue::fake([PollSubmission::class]);
    $doc = pipelineDoc($this->issuer);
    $last = count((array) config('lhdn.poll.backoff_seconds')) - 1;
    fakeLhdn()->failNextWith(LhdnException::terminal('nf', 404), 'get_submission');
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'get_document');
    pipelinePoll($this->issuer, (string) $doc->lhdn_submission_uid, $last);

    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted)->and($doc->lhdn_errors)->toBeNull();
});

it('retries rather than invalidating when a submission fails with a non-payload terminal status', function () {
    config(['lhdn.submission.max_attempts' => 3, 'lhdn.submission.retry_backoff_seconds' => [30]]);
    Queue::fake([SubmitDocuments::class, PollSubmission::class]);
    $doc = pipelineDoc($this->issuer);
    // 404 is about the endpoint we called, not about the invoice we sent.
    fakeLhdn()->failNextWith(LhdnException::terminal('no such route', 404), 'submit');
    pipelineSubmit($this->issuer);

    expect($doc->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($doc->submission_attempts_count)->toBe(1)
        ->and($doc->next_submission_at)->not->toBeNull()
        ->and($doc->lhdn_errors)->toBeNull()
        ->and($doc->last_submission_error['kind'])->toBe('terminal');
    Queue::assertPushed(SubmitDocuments::class);
});
