<?php

namespace App\Events;

use App\Enums\IssuerStatus;
use App\Models\Issuer;
use Illuminate\Foundation\Events\Dispatchable;

class IssuerStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Issuer $issuer,
        public readonly IssuerStatus $from,
        public readonly IssuerStatus $to,
    ) {}
}
