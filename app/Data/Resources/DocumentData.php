<?php

namespace App\Data\Resources;

use App\Models\Document;
use Spatie\LaravelData\Data;

class DocumentData extends Data
{
    /**
     * @param  array<string, mixed>  $buyer
     * @param  list<DocumentLineResourceData>  $lines
     * @param  array{system: string, ref: string}  $source
     * @param  array<string, mixed>|null  $original_document_ref
     * @param  array<string, mixed>|null  $payment
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $lhdn
     */
    public function __construct(
        public string $id,
        public string $issuer_id,
        public ?string $buyer_id,
        public ?string $group_id,
        public string $environment,
        public string $type,
        public string $lhdn_type_code,
        public string $status,
        public ?string $held_reason,
        public array $buyer,
        public string $currency,
        public ?string $exchange_rate,
        public string $issue_date,
        public DocumentTotalsData $totals,
        public array $lines,
        public bool $consolidate,
        public array $source,
        public ?array $original_document_ref,
        public ?array $payment,
        public ?array $metadata,
        public ?array $lhdn,
        public ?string $validated_at,
        public ?string $submitted_at,
        public ?string $cancelled_at,
        public ?string $cancel_reason,
        public ?string $consolidated_into_id,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Document $d): self
    {
        $d->loadMissing('lines');
        $hasLhdn = $d->lhdn_uuid !== null || $d->lhdn_submission_uid !== null || $d->lhdn_errors !== null;

        return new self(
            id: $d->id,
            issuer_id: $d->issuer_id,
            buyer_id: $d->buyer_id,
            group_id: $d->group_id,
            environment: $d->environment->value,
            type: $d->type->value,
            lhdn_type_code: $d->type->lhdnCode(),
            status: $d->status->value,
            held_reason: $d->held_reason?->value,
            buyer: $d->buyer_snapshot,
            currency: $d->currency,
            exchange_rate: $d->exchange_rate,
            issue_date: $d->issue_date->toDateString(),
            totals: DocumentTotalsData::fromModel($d),
            lines: array_values($d->lines->map(fn ($l) => DocumentLineResourceData::fromModel($l))->all()),
            consolidate: $d->consolidate,
            source: ['system' => $d->source_system, 'ref' => $d->source_ref],
            original_document_ref: $d->original_document_id !== null || $d->original_lhdn_uuid !== null
                ? ['document_id' => $d->original_document_id, 'lhdn_uuid' => $d->original_lhdn_uuid] : null,
            payment: $d->payment,
            metadata: $d->metadata,
            lhdn: $hasLhdn ? [
                'uuid' => $d->lhdn_uuid, 'long_id' => $d->lhdn_long_id, 'submission_uid' => $d->lhdn_submission_uid,
                'errors' => $d->lhdn_errors, 'status_at' => $d->lhdn_status_at?->toIso8601String(),
                'validation_url' => self::validationUrl($d),
            ] : null,
            validated_at: $d->validated_at?->toIso8601String(),
            submitted_at: $d->submitted_at?->toIso8601String(),
            cancelled_at: $d->cancelled_at?->toIso8601String(),
            cancel_reason: $d->cancel_reason,
            consolidated_into_id: $d->consolidated_into_id,
            created_at: $d->created_at->toIso8601String(),
            updated_at: $d->updated_at->toIso8601String(),
        );
    }

    /** The public MyInvois page for a validated document; only LHDN's own long id makes it shareable. */
    private static function validationUrl(Document $d): ?string
    {
        if ($d->lhdn_uuid === null || $d->lhdn_long_id === null) {
            return null;
        }
        $portal = rtrim((string) config("lhdn.environments.{$d->environment->value}.portal_base"), '/');

        return $portal === '' ? null : "{$portal}/{$d->lhdn_uuid}/share/{$d->lhdn_long_id}";
    }
}
