<?php

namespace App\Data\Requests;

use App\Enums\LhdnMode;
use Spatie\LaravelData\Attributes\Validation\Digits;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class UpdateIssuerData extends Data
{
    public function __construct(
        #[Max(255)] public string|Optional $name,
        #[Max(40)] public string|Optional|null $sst_number,
        #[Max(40)] public string|Optional|null $tourism_tax_number,
        #[Digits(5)] public string|Optional $msic_code,
        #[Max(300)] public string|Optional $business_activity_description,
        #[Max(150)] public string|Optional $address_line1,
        #[Max(150)] public string|Optional|null $address_line2,
        #[Max(150)] public string|Optional|null $address_line3,
        #[Max(10)] public string|Optional $postcode,
        #[Max(50)] public string|Optional $city,
        #[Size(2)] public string|Optional $state_code,
        #[Size(3)] public string|Optional $country_code,
        #[Email, Max(320)] public string|Optional $email,
        #[Max(20)] public string|Optional $phone,
        public LhdnMode|Optional $lhdn_mode,
        public bool|Optional $einvoice_required,
        public bool|Optional $consolidation_enabled,
    ) {}
}
