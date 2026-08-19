<?php

namespace App\Services\Issuers;

use App\Enums\IssuerStatus;
use App\Models\Issuer;

class IssuerActivator
{
    public function evaluate(Issuer $issuer): IssuerStatus
    {
        return match (true) {
            $issuer->status === IssuerStatus::Authorized && $issuer->hasValidCertificate() => IssuerStatus::Active,
            $issuer->status === IssuerStatus::Active && ! $issuer->hasValidCertificate() => IssuerStatus::Suspended,
            $issuer->status === IssuerStatus::Suspended && $issuer->authorized_at !== null && $issuer->hasValidCertificate() => IssuerStatus::Active,
            default => $issuer->status,
        };
    }

    public function apply(Issuer $issuer): void
    {
        $next = $this->evaluate($issuer);
        if ($next === $issuer->status) {
            return;
        }
        $issuer->status = $next;
        if ($next === IssuerStatus::Active) {
            $issuer->activated_at = now();
        }
        $issuer->save();
    }
}
