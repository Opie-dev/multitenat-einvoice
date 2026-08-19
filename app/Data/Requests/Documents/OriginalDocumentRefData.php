<?php

namespace App\Data\Requests\Documents;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class OriginalDocumentRefData extends Data
{
    public function __construct(
        public ?string $document_id = null,
        public ?string $lhdn_uuid = null,
    ) {}

    /**
     * Exactly one of document_id/lhdn_uuid. String rules like `required_without:lhdn_uuid`
     * resolve their parameter against the *root* payload, not this object's own scope, so
     * when nested (e.g. under `original_document_ref`) they never see the sibling field and
     * silently misbehave. Compute presence from $context->payload directly instead, via a
     * lazy Rule::requiredIf()/prohibitedIf() closure.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $hasDocumentId = fn () => filled(data_get($context->payload, 'document_id'));
        $hasLhdnUuid = fn () => filled(data_get($context->payload, 'lhdn_uuid'));

        return [
            'document_id' => [
                'nullable', 'string', 'max:26',
                Rule::requiredIf(fn () => ! $hasLhdnUuid()),
                Rule::prohibitedIf(fn () => $hasLhdnUuid()),
            ],
            'lhdn_uuid' => [
                'nullable', 'string', 'max:64',
                Rule::requiredIf(fn () => ! $hasDocumentId()),
            ],
        ];
    }
}
