<?php

namespace App\Data\Resources;

use App\Models\Buyer;
use Spatie\LaravelData\Data;

class BuyerData extends Data
{
    /** @param array<string, mixed>|null $tin_validation_result */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $tin,
        public ?string $id_type,
        public ?string $id_number,
        public ?string $sst_number,
        public ?string $email,
        public ?string $phone,
        public AddressData $address,
        public bool $general_public,
        public ?string $tin_validated_at,
        public ?array $tin_validation_result,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Buyer $buyer): self
    {
        return new self(
            id: $buyer->id,
            name: $buyer->name,
            tin: $buyer->tin,
            id_type: $buyer->id_type?->value,
            id_number: $buyer->id_number,
            sst_number: $buyer->sst_number,
            email: $buyer->email,
            phone: $buyer->phone,
            address: new AddressData(
                line1: $buyer->address_line1,
                line2: $buyer->address_line2,
                line3: $buyer->address_line3,
                postcode: $buyer->postcode,
                city: $buyer->city,
                state_code: $buyer->state_code,
                country_code: $buyer->country_code,
            ),
            general_public: $buyer->general_public,
            tin_validated_at: $buyer->tin_validated_at?->toIso8601String(),
            tin_validation_result: $buyer->tin_validation_result,
            created_at: $buyer->created_at->toIso8601String(),
            updated_at: $buyer->updated_at->toIso8601String(),
        );
    }
}
