<?php

namespace App\Console\Commands;

use App\Auth\Actor;
use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Events\CertificateExpired;
use App\Events\CertificateExpiring;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Services\Issuers\IssuerActivator;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily sweep of every issuer's certificate. An issuer whose certificate has
 * lapsed is suspended (spec §7.4); one still valid but nearing expiry gets a
 * single `certificate.expiring` notice per crossed threshold, deduped via
 * `issuer_secrets.expiry_notified_at_days`.
 *
 * This is a system job: it walks every tenant itself and binds a `system`
 * actor per tenant and environment, mirroring `LhdnDispatch`.
 */
class MonitorCertificates extends Command
{
    protected $signature = 'einvoice:monitor-certificates';

    protected $description = "Check every issuer's certificate expiry and notify or suspend as needed.";

    /**
     * Ascending so the first threshold that matches is the smallest one —
     * i.e. the closest-to-expiry notice still due, per the spec's "only one
     * notice per run" rule.
     */
    private const THRESHOLD_DAYS = [1, 7, 30];

    public function handle(TenantContext $context, IssuerActivator $activator): int
    {
        $checked = 0;

        $callerTenant = $context->tenantOrNull();
        $callerActor = $context->actor();
        $callerEnvironment = $context->environment();

        try {
            foreach (Tenant::query()->cursor() as $tenant) {
                foreach (Environment::cases() as $environment) {
                    $context->bind($tenant, new Actor('system', 'einvoice:monitor-certificates', 'monitor-certificates', ['*']), $environment);

                    try {
                        $checked += $this->sweep($environment, $activator);
                    } finally {
                        $context->clear();
                    }
                }
            }
        } finally {
            if ($callerTenant !== null) {
                $context->bind($callerTenant, $callerActor, $callerEnvironment);
            }
        }

        $this->info("Checked {$checked} issuer(s) with a certificate.");

        return self::SUCCESS;
    }

    private function sweep(Environment $environment, IssuerActivator $activator): int
    {
        $checked = 0;

        $issuers = Issuer::query()
            ->where('environment', $environment)
            ->whereNotNull('certificate_valid_until')
            ->with('secret')
            ->get();

        foreach ($issuers as $issuer) {
            $checked++;

            $validUntil = $issuer->certificate_valid_until;
            if ($validUntil === null) {
                continue; // whereNotNull() above guarantees this in practice; narrows the type for static analysis.
            }

            if ($validUntil->isPast()) {
                if ($issuer->status === IssuerStatus::Active) {
                    $activator->apply($issuer);
                    CertificateExpired::dispatch($issuer);
                }

                continue;
            }

            $secret = $issuer->secret;
            if ($secret === null) {
                continue;
            }

            $daysLeft = (int) ceil(now()->diffInSeconds($validUntil, false) / 86400);

            foreach (self::THRESHOLD_DAYS as $threshold) {
                $alreadyNotified = $secret->expiry_notified_at_days;
                if ($daysLeft <= $threshold && ($alreadyNotified === null || $alreadyNotified > $threshold)) {
                    CertificateExpiring::dispatch($issuer, $daysLeft);
                    $secret->forceFill(['expiry_notified_at_days' => $threshold])->save();
                    break;
                }
            }
        }

        return $checked;
    }
}
