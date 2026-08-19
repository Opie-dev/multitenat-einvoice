<?php

namespace App\Data\Requests;

use App\Enums\IdType;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\RequiredWith;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class UpdateBuyerData extends Data
{
    public function __construct(
        #[Max(300)] public string|Optional $name,
        #[Max(20)] public string|Optional|null $tin,
        #[RequiredWith('id_number')] public IdType|Optional|null $id_type,
        #[RequiredWith('id_type'), Max(30)] public string|Optional|null $id_number,
        #[Max(40)] public string|Optional|null $sst_number,
        #[Email, Max(320)] public string|Optional|null $email,
        #[Max(20)] public string|Optional|null $phone,
        #[Max(150)] public string|Optional|null $address_line1,
        #[Max(150)] public string|Optional|null $address_line2,
        #[Max(150)] public string|Optional|null $address_line3,
        #[Max(10)] public string|Optional|null $postcode,
        #[Max(50)] public string|Optional|null $city,
        #[Size(2)] public string|Optional|null $state_code,
        #[Size(3)] public string|Optional $country_code,
        public bool|Optional $general_public,
    ) {}
}
