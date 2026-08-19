<?php

namespace App\Services\Issuers;

use App\Enums\IssuerStatus;
use App\Events\IssuerActivated;
use App\Models\Issuer;
use App\Tenancy\TenantContext;

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
        if ($next === IssuerStatus::Active) {
            // On the sync queue connection, ReleaseHeldDocuments (dispatched
            // by the listener) runs inline here and BindTenantContext clears
            // the ambient tenant context once it finishes. Restore whatever
            // was bound before the dispatch so callers (e.g. the certificate
            // controller's audit log + response serialisation) keep working.
            $context = app(TenantContext::class);
            $tenant = $context->tenantOrNull();
            $actor = $context->actor();
            $environment = $context->environment();

            IssuerActivated::dispatch($issuer);

            if ($tenant !== null) {
                $context->bind($tenant, $actor, $environment);
            }
        }
    }
}
