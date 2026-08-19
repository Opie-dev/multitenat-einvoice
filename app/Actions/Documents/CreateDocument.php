<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Requests\Documents\DocumentLineData;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\DocumentTotals;
use App\Domain\Documents\Money;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates one document: idempotent on (source_system, source_ref, type) within the
 * tenant, validated and totalled before anything is written, then routed to the
 * status that reflects what the engine will actually do with it next.
 */
class CreateDocument
{
    public function __construct(
        private readonly DocumentSemanticValidator $semantics,
        private readonly ResolveBuyerSnapshot $buyers,
        private readonly TotalsCalculator $totals,
        private readonly DocumentStateMachine $stateMachine,
    ) {}

    public function handle(CreateDocumentData $data, ?string $groupId = null): DocumentCreated
    {
        $issuer = $this->issuer($data);
        if ($existing = $this->existingByNaturalKey($data)) {
            return $existing;
        }
        $this->semantics->validate($data, $issuer);
        $buyer = $this->buyers->resolve($data->buyer);
        $totals = $this->totals($data);

        try {
            $document = DB::transaction(function () use ($data, $issuer, $buyer, $totals, $groupId): Document {
                $document = $this->persist($data, $issuer, $buyer, $totals, $groupId);
                $this->stateMachine->transition($document, DocumentStatus::Validated);
                $this->route($document, $issuer, $data);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with an identical create: settle it exactly like the pre-check would.
            return $this->existingByNaturalKey($data)
                ?? throw ProblemException::conflict('A document with this source reference already exists.', 'idempotency_conflict');
        }

        return new DocumentCreated($document, replayed: false);
    }

    private function issuer(CreateDocumentData $data): Issuer
    {
        return Issuer::forCurrentEnvironment()->find($data->issuer_id)
            ?? throw new ProblemException(404, 'Not Found', 'Issuer not found.', 'issuer_not_found');
    }

    /** The natural key is unique per tenant *and* environment: the same source ref may be reused in sandbox and production. */
    private function existingByNaturalKey(CreateDocumentData $data): ?DocumentCreated
    {
        $existing = Document::forCurrentEnvironment()
            ->where('source_system', $data->source->system)
            ->where('source_ref', $data->source->ref)
            ->where('type', $data->type)
            ->first();

        if ($existing === null) {
            return null;
        }
        if ($existing->payload_hash !== $data->payloadHash()) {
            throw ProblemException::conflict('A document with this source reference already exists with a different payload.', 'idempotency_conflict');
        }

        return new DocumentCreated($existing, replayed: true);
    }

    private function totals(CreateDocumentData $data): DocumentTotals
    {
        try {
            return $this->totals->calculate($this->lines($data), $data->totals);
        } catch (TotalsMismatch $e) {
            throw ValidationException::withMessages([
                // /lines/0/total becomes lines.0.total, which the problem+json handler renders back as a pointer.
                str_replace('/', '.', ltrim($e->pointer, '/')) => "Value {$e->given} does not match the computed {$e->expected}.",
            ]);
        }
    }

    /**
     * Re-indexed 0..n-1 so the persisted line at position $i+1 always pairs with
     * DocumentTotals::$lines[$i], whatever keys the DataCollection carries.
     *
     * @return list<DocumentLineData>
     */
    private function lines(CreateDocumentData $data): array
    {
        /** @var list<DocumentLineData> */
        return array_values($data->lines->items());
    }

    /** @param array{buyer_id: ?string, snapshot: array<string, mixed>} $buyer */
    private function persist(CreateDocumentData $data, Issuer $issuer, array $buyer, DocumentTotals $totals, ?string $groupId): Document
    {
        $document = Document::create([
            'issuer_id' => $issuer->id,
            'buyer_id' => $buyer['buyer_id'],
            'group_id' => $groupId,
            'environment' => $issuer->environment,
            'type' => $data->type,
            'status' => DocumentStatus::Draft,
            'buyer_snapshot' => $buyer['snapshot'],
            'currency' => $data->currency,
            'exchange_rate' => $data->exchange_rate !== null ? Money::str(Money::of($data->exchange_rate), 6) : null,
            'issue_date' => $data->issue_date ?? now('Asia/Kuala_Lumpur')->toDateString(),
            'consolidate' => $data->consolidate,
            'source_system' => $data->source->system,
            'source_ref' => $data->source->ref,
            'original_document_id' => $data->original_document_ref?->document_id,
            'original_lhdn_uuid' => $data->original_document_ref?->lhdn_uuid,
            'payment' => $data->payment?->toArray(),
            'metadata' => $data->metadata,
            'payload_hash' => $data->payloadHash(),
        ] + $totals->toStrings());

        foreach ($this->lines($data) as $i => $line) {
            $lt = $totals->lines[$i];
            $document->lines()->create([
                'position' => $i + 1,
                'classification_code' => $line->classification_code,
                'description' => $line->description,
                'quantity' => Money::str($lt->quantity, 4),
                'unit_code' => $line->unit_code,
                'unit_price' => Money::str($lt->unitPrice, 4),
                'discount_amount' => Money::str($lt->discount),
                'discount_rate' => $line->discount_rate !== null ? Money::str(Money::of($line->discount_rate), 4) : null,
                'tax_type' => $line->tax_type,
                'tax_rate' => $lt->taxRate !== null ? Money::str($lt->taxRate, 4) : null,
                'tax_amount' => Money::str($lt->taxAmount),
                'tax_exemption_reason' => $line->tax_exemption_reason,
                'subtotal' => Money::str($lt->subtotal),
                'total' => Money::str($lt->total),
                'metadata' => $line->metadata,
            ]);
        }

        return $document;
    }

    private function route(Document $document, Issuer $issuer, CreateDocumentData $data): void
    {
        if (! $data->submit) {
            return; // stays validated until POST /documents/{id}/submit
        }
        if (! $issuer->einvoice_required) {
            $this->stateMachine->transition($document, DocumentStatus::Held, HeldReason::EinvoiceNotRequired->value);

            return;
        }
        if ($data->consolidate) {
            $this->stateMachine->transition($document, DocumentStatus::AwaitingConsolidation);

            return;
        }
        if ($issuer->status !== IssuerStatus::Active) {
            $reason = $issuer->status === IssuerStatus::Suspended ? HeldReason::CertificateExpired : HeldReason::IssuerNotActive;
            $this->stateMachine->transition($document, DocumentStatus::Held, $reason->value);

            return;
        }
        $this->stateMachine->transition($document, DocumentStatus::Queued);
    }
}
