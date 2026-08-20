<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case DocumentValidated = 'document.validated';
    case DocumentHeld = 'document.held';
    case DocumentQueued = 'document.queued';
    case DocumentSubmitted = 'document.submitted';
    case DocumentValid = 'document.valid';
    case DocumentInvalid = 'document.invalid';
    case DocumentCancelled = 'document.cancelled';
    case DocumentRejected = 'document.rejected';
    case DocumentConsolidated = 'document.consolidated';
    case DocumentConsolidationFailed = 'document.consolidation_failed';
    case IssuerStatusChanged = 'issuer.status_changed';
    case CertificateExpiring = 'certificate.expiring';
    case CertificateExpired = 'certificate.expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
