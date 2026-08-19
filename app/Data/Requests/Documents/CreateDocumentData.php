<?php

namespace App\Data\Requests\Documents;

use App\Enums\DocumentType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateDocumentData extends Data
{
    /**
     * @param  DataCollection<int, DocumentLineData>  $lines
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public DocumentType $type,
        #[Max(26)] public string $issuer_id,
        public DocumentBuyerData $buyer,
        #[DataCollectionOf(DocumentLineData::class)] public DataCollection $lines,
        public DocumentSourceData $source,
        public string $currency = 'MYR',
        public int|float|string|null $exchange_rate = null,
        public ?string $issue_date = null,
        public ?OriginalDocumentRefData $original_document_ref = null,
        public ?DocumentPaymentData $payment = null,
        public ?DocumentTotalsInputData $totals = null,
        public bool $consolidate = false,
        public bool $submit = true,
        public ?array $metadata = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'required_unless:currency,MYR'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function payloadHash(): string
    {
        $array = $this->toArray();
        unset($array['submit']);

        return hash('sha256', (string) json_encode(self::canonical($array), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v);
            } elseif (is_int($v) || is_float($v)) {
                $value[$k] = (string) $v; // "2" and 2 hash the same
            }
        }

        return $value;
    }
}
