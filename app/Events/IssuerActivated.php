<?php

namespace App\Events;

use App\Models\Issuer;
use Illuminate\Foundation\Events\Dispatchable;

class IssuerActivated
{
    use Dispatchable;

    public function __construct(public readonly Issuer $issuer) {}
}
