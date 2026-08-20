<?php

namespace App\Events;

use App\Models\Issuer;
use Illuminate\Foundation\Events\Dispatchable;

class CertificateExpiring
{
    use Dispatchable;

    public function __construct(
        public readonly Issuer $issuer,
        public readonly int $daysLeft,
    ) {}
}
