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
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $skipped = 0;

        $callerTenant = $context->tenantOrNull();
        $callerActor = $context->actor();
        $callerEnvironment = $context->environment();

        try {
            foreach (Tenant::query()->cursor() as $tenant) {
                foreach (Environment::cases() as $environment) {
                    $context->bind($tenant, new Actor('system', 'einvoice:monitor-certificates', 'monitor-certificates', ['*']), $environment);

                    try {
                        $outcome = $this->sweep($tenant, $environment, $activator);
                        $checked += $outcome['checked'];
                        $skipped += $outcome['skipped'];
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

        if ($skipped > 0) {
            // The scheduler only notices a non-zero exit, and a certificate nobody
            // was warned about is exactly the thing an operator has to see.
            $this->error("{$skipped} issuer(s) skipped; see the certificate.monitor_skipped log entries.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array{checked: int, skipped: int} */
    private function sweep(Tenant $tenant, Environment $environment, IssuerActivator $activator): array
    {
        $checked = 0;
        $skipped = 0;

        $issuers = Issuer::query()
            ->where('environment', $environment)
            ->whereNotNull('certificate_valid_until')
            ->with('secret')
            ->get();

        foreach ($issuers as $issuer) {
            $checked++;

            // One issuer's failure — a webhook listener blowing up, say — must not
            // abort the sweep for everyone else, but it must not pass silently either.
            try {
                $this->check($issuer, $activator);
            } catch (Throwable $e) {
                $skipped++;
                report($e);
                Log::error('certificate.monitor_skipped', [
                    'tenant_id' => $tenant->id,
                    'issuer_id' => $issuer->id,
                    'environment' => $environment->value,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Issuer {$issuer->id}: {$e->getMessage()}");
            }
        }

        return ['checked' => $checked, 'skipped' => $skipped];
    }

    private function check(Issuer $issuer, IssuerActivator $activator): void
    {
        $validUntil = $issuer->certificate_valid_until;
        if ($validUntil === null) {
            return; // whereNotNull() above guarantees this in practice; narrows the type for static analysis.
        }

        if ($validUntil->isPast()) {
            if ($issuer->status === IssuerStatus::Active) {
                $activator->apply($issuer);
                CertificateExpired::dispatch($issuer);
            }

            return;
        }

        $secret = $issuer->secret;
        if ($secret === null) {
            return;
        }

        $daysLeft = (int) ceil(now()->diffInSeconds($validUntil, false) / 86400);

        foreach (self::THRESHOLD_DAYS as $threshold) {
            $alreadyNotified = $secret->expiry_notified_at_days;
            if ($daysLeft <= $threshold && ($alreadyNotified === null || $alreadyNotified > $threshold)) {
                // Marked before dispatching: at-most-once beats at-least-once here,
                // because a listener that throws would otherwise re-send the same
                // threshold's notice on every subsequent run.
                $secret->forceFill(['expiry_notified_at_days' => $threshold])->save();
                CertificateExpiring::dispatch($issuer, $daysLeft);
                break;
            }
        }
    }
}
