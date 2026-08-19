<?php

namespace App\Data\Requests;

use App\Enums\Environment;
use App\Models\ApiKey;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateApiKeyData extends Data
{
    /** @param string[] $abilities */
    public function __construct(
        #[Max(255)]
        public string $name,
        public Environment $environment,
        public array $abilities,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiKey::ABILITIES)],
        ];
    }
}
