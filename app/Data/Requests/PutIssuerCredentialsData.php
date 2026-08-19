<?php

namespace App\Data\Requests;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class PutIssuerCredentialsData extends Data
{
    public function __construct(
        #[Max(255)] public string $client_id,
        #[Max(1024)] public string $client_secret,
    ) {}
}
