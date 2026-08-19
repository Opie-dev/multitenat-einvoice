<?php

namespace App\Data\Requests\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Closure;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentFilterData extends Data
{
    public function __construct(
        public ?DocumentStatus $status = null,
        public ?string $issuer_id = null,
        public ?string $group_id = null,
        public ?DocumentType $type = null,
        public ?string $source_system = null,
        public ?string $source_ref = null,
        public ?string $issue_date_from = null,
        public ?string $issue_date_to = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, DocumentStatus::cases()))],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, DocumentType::cases()))],
            'issuer_id' => ['nullable', 'string', 'max:26'],
            'group_id' => ['nullable', 'string', 'max:26'],
            'source_system' => ['nullable', 'string', 'max:50'],
            'source_ref' => ['nullable', 'string', 'max:191'],
            'issue_date_from' => ['nullable', 'date_format:Y-m-d'],
            'issue_date_to' => [
                'nullable', 'date_format:Y-m-d',
                // Y-m-d strings compare lexicographically, so no date parsing is needed here.
                function (string $attribute, mixed $value, Closure $fail) use ($context): void {
                    $from = data_get($context->payload, 'issue_date_from');
                    if (is_string($from) && is_string($value) && $value < $from) {
                        $fail('issue_date_to must not be earlier than issue_date_from.');
                    }
                },
            ],
        ];
    }
}
