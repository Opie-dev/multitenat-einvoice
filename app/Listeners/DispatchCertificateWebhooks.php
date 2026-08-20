<?php

namespace App\Listeners;

use App\Events\CertificateExpired;
use App\Events\CertificateExpiring;
use App\Services\Audit\AuditLogger;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookPayload;

class DispatchCertificateWebhooks
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly AuditLogger $audit,
    ) {}

    public function handleExpiring(CertificateExpiring $event): void
    {
        $extra = [
            'expires_at' => $event->issuer->certificate_valid_until?->toIso8601String(),
            'days_left' => $event->daysLeft,
        ];

        $this->dispatcher->dispatch(
            'certificate.expiring',
            $event->issuer->environment,
            WebhookPayload::issuer('certificate.expiring', $event->issuer, $extra),
        );

        $this->audit->record('certificate.expiring', $event->issuer, $extra);
    }

    public function handleExpired(CertificateExpired $event): void
    {
        $extra = ['expired_at' => $event->issuer->certificate_valid_until?->toIso8601String()];

        $this->dispatcher->dispatch(
            'certificate.expired',
            $event->issuer->environment,
            WebhookPayload::issuer('certificate.expired', $event->issuer, $extra),
        );

        $this->audit->record('certificate.expired', $event->issuer, $extra);
    }
}
