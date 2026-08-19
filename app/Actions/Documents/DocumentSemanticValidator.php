<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Requests\Documents\DocumentLineData;
use App\Domain\Documents\Money;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Validation\ValidationException;

/**
 * Cross-field rules the DTO cannot express on its own: they need the issuer and
 * the tenant's existing documents. Errors are returned as a flat map so a batch
 * can prefix and merge them instead of failing on the first bad item.
 */
class DocumentSemanticValidator
{
    public const METADATA_MAX_BYTES = 8192;

    /** @return array<string, string> errors keyed by dotted field; empty when valid */
    public function errors(CreateDocumentData $data, Issuer $issuer): array
    {
        $errors = [];

        if ($data->type->requiresOriginalRef() && $data->original_document_ref === null) {
            $errors['original_document_ref'] = 'Credit, debit and refund notes must reference the original document.';
        }
        if (! $data->type->requiresOriginalRef() && $data->original_document_ref !== null) {
            $errors['original_document_ref'] = 'Only credit, debit and refund notes may reference an original document.';
        }
        if ($data->original_document_ref?->document_id !== null
            && ! Document::forCurrentEnvironment()->whereKey($data->original_document_ref->document_id)->exists()) {
            $errors['original_document_ref.document_id'] = 'Original document not found.';
        }

        if ($data->consolidate) {
            if (! $data->buyer->general_public) {
                $errors['consolidate'] = 'Only general-public (B2C) documents can be consolidated.';
            } elseif (! $issuer->consolidation_enabled) {
                $errors['consolidate'] = 'Consolidation is not enabled for this issuer.';
            }
        }

        if ($data->metadata !== null && strlen((string) json_encode($data->metadata)) > self::METADATA_MAX_BYTES) {
            $errors['metadata'] = 'metadata must not exceed 8 KB.';
        }

        /** @var list<DocumentLineData> $lines */
        $lines = array_values($data->lines->items());
        foreach ($lines as $i => $line) {
            // Exempt (E) and zero-rated/not-applicable (06) lines carry no tax figures.
            if (in_array($line->tax_type, ['E', '06'], true)) {
                if ($line->tax_rate !== null && Money::of($line->tax_rate)->isPositive()) {
                    $errors["lines.{$i}.tax_rate"] = "Tax type {$line->tax_type} does not allow a tax rate/amount.";
                } elseif ($line->tax_amount !== null && Money::of($line->tax_amount)->isPositive()) {
                    $errors["lines.{$i}.tax_amount"] = "Tax type {$line->tax_type} does not allow a tax rate/amount.";
                }
            }
            if ($line->metadata !== null && strlen((string) json_encode($line->metadata)) > self::METADATA_MAX_BYTES) {
                $errors["lines.{$i}.metadata"] = 'metadata must not exceed 8 KB.';
            }
        }

        return $errors;
    }

    public function validate(CreateDocumentData $data, Issuer $issuer): void
    {
        $errors = $this->errors($data, $issuer);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
