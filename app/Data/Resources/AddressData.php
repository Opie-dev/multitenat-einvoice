<?php

namespace App\Data\Resources;

use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(
        public ?string $line1,
        public ?string $line2,
        public ?string $line3,
        public ?string $postcode,
        public ?string $city,
        public ?string $state_code,
        public ?string $country_code,
    ) {}
}
