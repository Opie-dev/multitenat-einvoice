<?php

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\CreateDocumentBatch;
use App\Actions\Documents\SubmitDocument;
use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Exceptions\ProblemException;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

function docPayload(Issuer $issuer, array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'invoice',
        'issuer_id' => $issuer->id,
        'buyer' => ['general_public' => true],
        'lines' => [[
            'classification_code' => '022', 'description' => 'Widget', 'quantity' => 2,
            'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6,
        ]],
        'source' => ['system' => 'catalog', 'ref' => 'order-'.bin2hex(random_bytes(3))],
    ], $overrides);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->create = app(CreateDocument::class);
});

it('creates a validated document, computes totals, snapshots the buyer and queues it', function () {
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer)));
    $doc = $r->document->refresh();
    expect($r->replayed)->toBeFalse()
        ->and($doc->status)->toBe(DocumentStatus::Queued)
        ->and($doc->environment)->toBe(Environment::Sandbox)
        ->and($doc->total_payable)->toBe('22.26')
        ->and($doc->tax_total)->toBe('1.26')
        ->and($doc->buyer_snapshot['tin'])->toBe('EI00000000010')
        ->and($doc->lines)->toHaveCount(1)
        ->and($doc->lines[0]->total)->toBe('22.26')
        ->and($doc->events()->pluck('to_status')->map->value->all())->toBe(['validated', 'queued']);
});

it('replays on identical natural key + payload and conflicts on a different payload', function () {
    $payload = docPayload($this->issuer);
    $a = $this->create->handle(CreateDocumentData::from($payload));
    $b = $this->create->handle(CreateDocumentData::from($payload));
    expect($b->replayed)->toBeTrue()->and($b->document->id)->toBe($a->document->id);
    $payload['lines'][0]['quantity'] = 3;
    expect(fn () => $this->create->handle(CreateDocumentData::from($payload)))
        ->toThrow(fn (ProblemException $e) => expect($e->status)->toBe(409)->and($e->problemCode)->toBe('idempotency_conflict'));
});

it('lets the same source reference create one document per environment', function () {
    $payload = docPayload($this->issuer);
    $sandbox = $this->create->handle(CreateDocumentData::from($payload));

    app(TenantContext::class)->bind($this->tenant, null, Environment::Production);
    $productionIssuer = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Production]);
    $production = $this->create->handle(CreateDocumentData::from(docPayload($productionIssuer, ['source' => $payload['source']])));

    expect($production->replayed)->toBeFalse()
        ->and($production->document->id)->not->toBe($sandbox->document->id)
        ->and($production->document->environment)->toBe(Environment::Production)
        ->and(Document::withoutGlobalScopes()->count())->toBe(2);
});

it('holds documents for inactive issuers and for issuers below the threshold', function () {
    $draftIssuer = Issuer::factory()->for($this->tenant)->create();
    $r = $this->create->handle(CreateDocumentData::from(docPayload($draftIssuer)));
    expect($r->document->status)->toBe(DocumentStatus::Held)->and($r->document->held_reason)->toBe(HeldReason::IssuerNotActive);

    $small = Issuer::factory()->for($this->tenant)->active()->create(['einvoice_required' => false]);
    $r2 = $this->create->handle(CreateDocumentData::from(docPayload($small)));
    expect($r2->document->held_reason)->toBe(HeldReason::EinvoiceNotRequired);
});

it('routes consolidated B2C documents to awaiting_consolidation and rejects consolidation misuse', function () {
    $issuer = Issuer::factory()->for($this->tenant)->active()->create(['consolidation_enabled' => true]);
    $r = $this->create->handle(CreateDocumentData::from(docPayload($issuer, ['consolidate' => true])));
    expect($r->document->status)->toBe(DocumentStatus::AwaitingConsolidation);

    $buyer = Buyer::factory()->for($this->tenant)->create();
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($issuer, ['consolidate' => true, 'buyer' => ['general_public' => false, 'buyer_id' => $buyer->id]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('consolidate'));
});

it('rejects consolidation when the issuer has it disabled', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['consolidate' => true]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['consolidate'][0])->toContain('not enabled'));
});

it('resolves buyer_id to a snapshot and 404s unknown buyers; requires original refs on notes', function () {
    $buyer = Buyer::factory()->for($this->tenant)->create(['name' => 'Ali']);
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['buyer' => ['general_public' => false, 'buyer_id' => $buyer->id]])));
    expect($r->document->buyer_id)->toBe($buyer->id)->and($r->document->buyer_snapshot['name'])->toBe('Ali');

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['buyer' => ['general_public' => false, 'buyer_id' => '01J00000000000000000000000']]))))
        ->toThrow(fn (ProblemException $e) => expect($e->status)->toBe(404)->and($e->problemCode)->toBe('buyer_not_found'));

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['type' => 'credit_note']))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('original_document_ref'));

    $note = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['type' => 'credit_note', 'original_document_ref' => ['document_id' => $r->document->id]])));
    expect($note->document->original_document_id)->toBe($r->document->id);
});

it('rejects an original ref on a plain invoice and an unknown original document', function () {
    $other = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer)));

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['original_document_ref' => ['document_id' => $other->document->id]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('original_document_ref'));

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, [
        'type' => 'credit_note', 'original_document_ref' => ['document_id' => '01J00000000000000000000000'],
    ]))))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('original_document_ref.document_id'));
});

it('rejects a buyer that names no mode at all', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['buyer' => ['general_public' => false]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('buyer'));
});

it('snapshots an inline buyer', function () {
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, [
        'buyer' => ['general_public' => false, 'name' => 'Inline Sdn Bhd', 'tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '202001000001'],
    ])));
    expect($r->document->buyer_id)->toBeNull()
        ->and($r->document->buyer_snapshot['name'])->toBe('Inline Sdn Bhd')
        ->and($r->document->buyer_snapshot['country_code'])->toBe('MYS')
        ->and($r->document->buyer_snapshot['general_public'])->toBeFalse();
});

it('rejects metadata larger than 8 KB', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['metadata' => ['blob' => str_repeat('x', 9000)]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('metadata'));
});

it('rejects tax figures that contradict an exempt tax type', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, [
        'lines' => [['tax_type' => 'E', 'tax_rate' => 6, 'tax_exemption_reason' => 'Exempt under Schedule A']],
    ]))))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.0.tax_rate'));

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, [
        'lines' => [['tax_type' => '06', 'tax_rate' => null, 'tax_amount' => '1.00']],
    ]))))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.0.tax_amount'));
});

it('rejects line metadata larger than 8 KB', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, [
        'lines' => [['metadata' => ['blob' => str_repeat('x', 9000)]]],
    ]))))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.0.metadata'));
});

it('maps totals mismatches to validation errors with dotted keys', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['lines' => [['total' => '99.00']]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.0.total'));
});

it('keeps submit=false documents at validated and SubmitDocument queues them', function () {
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['submit' => false])));
    expect($r->document->status)->toBe(DocumentStatus::Validated);
    $doc = app(SubmitDocument::class)->handle($r->document);
    expect($doc->status)->toBe(DocumentStatus::Queued);
    expect(fn () => app(SubmitDocument::class)->handle($doc))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('invalid_transition'));
});

it('refuses to submit for issuers that are not active or not required to e-invoice', function () {
    $small = Issuer::factory()->for($this->tenant)->active()->create(['einvoice_required' => false]);
    $held = $this->create->handle(CreateDocumentData::from(docPayload($small)))->document;
    expect(fn () => app(SubmitDocument::class)->handle($held))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('einvoice_not_required'));

    $draft = Issuer::factory()->for($this->tenant)->create();
    $notActive = $this->create->handle(CreateDocumentData::from(docPayload($draft)))->document;
    expect(fn () => app(SubmitDocument::class)->handle($notActive))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('issuer_not_active'));
});

it('404s an issuer from another environment or tenant', function () {
    $prod = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Production]);
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($prod))))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('issuer_not_found'));

    $other = Issuer::factory()->active()->create(['environment' => Environment::Sandbox]);
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($other))))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('issuer_not_found'));
});

it('creates a batch under one group id', function () {
    $batch = CreateDocumentBatchData::from(['documents' => [
        docPayload($this->issuer),
        docPayload($this->issuer),
    ]]);
    $result = app(CreateDocumentBatch::class)->handle($batch);

    expect($result['documents'])->toHaveCount(2)
        ->and($result['group_id'])->toBeString()
        ->and(Document::query()->where('group_id', $result['group_id'])->count())->toBe(2)
        ->and($result['documents'][0]->document->group_id)->toBe($result['group_id']);
});

it('reports every batch item error in one validation exception and persists nothing', function () {
    $batch = CreateDocumentBatchData::from(['documents' => [
        docPayload($this->issuer),
        docPayload($this->issuer, ['type' => 'credit_note']),
        docPayload($this->issuer, ['lines' => [['total' => '99.00']]]),
    ]]);

    expect(fn () => app(CreateDocumentBatch::class)->handle($batch))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())
            ->toHaveKey('documents.1.original_document_ref')
            ->toHaveKey('documents.2.lines.0.total'));

    expect(Document::query()->count())->toBe(0);
});
