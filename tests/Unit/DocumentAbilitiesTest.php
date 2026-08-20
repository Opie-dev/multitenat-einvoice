<?php

use App\Actions\Documents\SubmitDocument;
use App\Domain\Documents\DocumentAbilities;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Pdf\DocumentPdfGenerator;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($tenant)->create();
});

it('keeps SubmitDocument::RESUBMITTABLE_STATUSES in sync with the expectations exercised below', function () {
    // If this fails, either the constant changed or the match arms below
    // (and DocumentAbilities' can_resubmit behaviour) need to change with it.
    expect(SubmitDocument::RESUBMITTABLE_STATUSES)->toBe([
        DocumentStatus::Validated,
        DocumentStatus::Held,
        DocumentStatus::Invalid,
    ]);
});

it('keeps DocumentPdfGenerator::AVAILABLE_STATUSES in sync with the expectations exercised below', function () {
    // If this fails, either the constant changed or the match arms below
    // (and DocumentAbilities' can_pdf behaviour) need to change with it.
    expect(DocumentPdfGenerator::AVAILABLE_STATUSES)->toBe([
        DocumentStatus::Valid,
        DocumentStatus::Cancelled,
        DocumentStatus::Rejected,
    ]);
});

it('derives abilities for every document status with no LHDN uuid and no window context', function (DocumentStatus $status) {
    $document = Document::factory()->for($this->issuer)->create(['status' => $status]);

    // Mirrors SubmitDocument::RESUBMITTABLE_STATUSES (asserted in sync with
    // this list above). The state machine's TRANSITIONS map additionally
    // allows `awaiting_consolidation` -> `queued`, but that edge has no
    // caller anywhere in the codebase and SubmitDocument's whitelist
    // excludes it regardless.
    $expectedResubmit = match ($status) {
        DocumentStatus::Validated, DocumentStatus::Held, DocumentStatus::Invalid => true,
        DocumentStatus::Draft, DocumentStatus::Queued, DocumentStatus::Submitted,
        DocumentStatus::Valid, DocumentStatus::Cancelled, DocumentStatus::Rejected,
        DocumentStatus::AwaitingConsolidation, DocumentStatus::Consolidated => false,
    };

    // Without an lhdn_uuid, cancel is never legal (only `valid` documents are
    // cancellable in principle, and CancelDocument additionally requires a
    // uuid to send LHDN a cancellation) and PDF is never servable (the PDF
    // controller requires a uuid too).
    expect(DocumentAbilities::for($document))->toBe([
        'can_cancel' => false,
        'can_resubmit' => $expectedResubmit,
        'can_pdf' => false,
    ]);
})->with(fn () => DocumentStatus::cases());

it('derives can_pdf for every document status once an LHDN uuid is present', function (DocumentStatus $status) {
    $document = Document::factory()->for($this->issuer)->create([
        'status' => $status,
        'lhdn_uuid' => Str::upper(Str::random(26)),
    ]);

    // Mirrors DocumentPdfGenerator::AVAILABLE_STATUSES (asserted in sync
    // with this list above).
    $expectedPdf = match ($status) {
        DocumentStatus::Valid, DocumentStatus::Cancelled, DocumentStatus::Rejected => true,
        DocumentStatus::Draft, DocumentStatus::Validated, DocumentStatus::Held, DocumentStatus::Queued,
        DocumentStatus::Submitted, DocumentStatus::Invalid, DocumentStatus::AwaitingConsolidation,
        DocumentStatus::Consolidated => false,
    };

    expect(DocumentAbilities::for($document)['can_pdf'])->toBe($expectedPdf);
})->with(fn () => DocumentStatus::cases());

it('allows cancelling a valid document with a uuid inside the 72-hour window', function () {
    $document = Document::factory()->for($this->issuer)->valid()->create([
        'lhdn_status_at' => now()->subHours(71),
    ]);

    expect(DocumentAbilities::for($document)['can_cancel'])->toBeTrue();
});

it('forbids cancelling a valid document once the 72-hour window has closed', function () {
    $document = Document::factory()->for($this->issuer)->valid()->create([
        'lhdn_status_at' => now()->subHours(73),
    ]);

    expect(DocumentAbilities::for($document)['can_cancel'])->toBeFalse();
});

it('forbids cancelling a valid, in-window document that has no LHDN uuid', function () {
    $document = Document::factory()->for($this->issuer)->valid()->create([
        'lhdn_status_at' => now()->subHour(),
        'lhdn_uuid' => null,
    ]);

    expect(DocumentAbilities::for($document)['can_cancel'])->toBeFalse();
});
