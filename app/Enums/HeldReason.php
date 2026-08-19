<?php

namespace App\Enums;

enum HeldReason: string
{
    case IssuerNotActive = 'issuer_not_active';
    case CertificateExpired = 'certificate_expired';
    case LhdnCredentialsInvalid = 'lhdn_credentials_invalid';
    case LhdnUnavailable = 'lhdn_unavailable';
    case EinvoiceNotRequired = 'einvoice_not_required';

    public function releasableOnIssuerActivation(): bool
    {
        return in_array($this, [self::IssuerNotActive, self::CertificateExpired], true);
    }
}
