<?php

namespace App\Data\Requests;

use App\Enums\IdType;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\RequiredWith;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateBuyerData extends Data
{
    public function __construct(
        #[Max(300)] public string $name,
        #[Max(20)] public ?string $tin = null,
        #[RequiredWith('id_number')] public ?IdType $id_type = null,
        #[RequiredWith('id_type'), Max(30)] public ?string $id_number = null,
        #[Max(40)] public ?string $sst_number = null,
        #[Email, Max(320)] public ?string $email = null,
        #[Max(20)] public ?string $phone = null,
        #[Max(150)] public ?string $address_line1 = null,
        #[Max(150)] public ?string $address_line2 = null,
        #[Max(150)] public ?string $address_line3 = null,
        #[Max(10)] public ?string $postcode = null,
        #[Max(50)] public ?string $city = null,
        #[Size(2)] public ?string $state_code = null,
        #[Size(3)] public string $country_code = 'MYS',
        public bool $general_public = false,
    ) {}

    /**
     * Properties with a null default are skipped by the rule inferrer when
     * absent from the payload, which would silently defeat #[RequiredWith]
     * for id_type/id_number. Restate both explicitly so the cross
     * requirement is always enforced, regardless of which side is missing.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'id_type' => ['nullable', new Enum(IdType::class), 'required_with:id_number'],
            'id_number' => ['nullable', 'string', 'max:30', 'required_with:id_type'],
        ];
    }
}
