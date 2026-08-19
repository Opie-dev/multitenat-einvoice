<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateDocumentBatchData extends Data
{
    /** @param DataCollection<int, CreateDocumentData> $documents */
    public function __construct(
        #[DataCollectionOf(CreateDocumentData::class)] public DataCollection $documents,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return ['documents' => ['required', 'array', 'min:1', 'max:100']];
    }
}
