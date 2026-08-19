<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

class DocumentPaymentData extends Data
{
    public function __construct(
        #[Regex('/^0[1-8]$/')] public ?string $mode = null,
        #[Max(300)] public ?string $terms = null,
        #[Date] public ?string $paid_at = null,
        #[Max(150)] public ?string $payment_ref = null,
    ) {}
}
