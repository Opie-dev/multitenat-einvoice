<?php

namespace App\Data\Requests;

use App\Enums\IdType;
use App\Enums\LhdnMode;
use Spatie\LaravelData\Attributes\Validation\Digits;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;

class CreateIssuerData extends Data
{
    public function __construct(
        #[Max(255)] public string $name,
        #[Regex('/^[A-Z]{1,2}[0-9]{10,12}$/')] public string $tin,
        public IdType $id_type,
        #[Max(30)] public string $id_number,
        #[Digits(5)] public string $msic_code,
        #[Max(300)] public string $business_activity_description,
        #[Max(150)] public string $address_line1,
        #[Max(10)] public string $postcode,
        #[Max(50)] public string $city,
        #[Size(2)] public string $state_code,
        #[Email, Max(320)] public string $email,
        #[Max(20)] public string $phone,
        public LhdnMode $lhdn_mode,
        #[Max(40)] public ?string $sst_number = null,
        #[Max(40)] public ?string $tourism_tax_number = null,
        #[Max(150)] public ?string $address_line2 = null,
        #[Max(150)] public ?string $address_line3 = null,
        #[Size(3)] public string $country_code = 'MYS',
        public bool $einvoice_required = true,
        public bool $consolidation_enabled = false,
    ) {}
}
