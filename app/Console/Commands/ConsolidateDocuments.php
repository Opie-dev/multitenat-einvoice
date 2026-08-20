<?php

namespace App\Console\Commands;

use App\Actions\Consolidation\ConsolidateIssuerMonth;
use App\Auth\Actor;
use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Daily sweep that consolidates the previous month's B2C receipts. Like
 * einvoice:lhdn-dispatch this is a system job: it walks every tenant itself and
 * binds a `system` actor per tenant and environment so the usual tenant scoping
 * still applies, then hands the caller's context back.
 */
class ConsolidateDocuments extends Command
{
    protected $signature = 'einvoice:consolidate {--month= : Month to consolidate as YYYY-MM (default: the previous month)}';

    protected $description = 'Consolidate a month of B2C receipts into one invoice per issuer and currency.';

    public function handle(TenantContext $context, ConsolidateIssuerMonth $action): int
    {
        $month = $this->month();
        if ($month === null) {
            $this->error('The --month option must be formatted as YYYY-MM.');

            return self::FAILURE;
        }

        $parents = 0;
        $consolidated = 0;
        $callerTenant = $context->tenantOrNull();
        $callerActor = $context->actor();
        $callerEnvironment = $context->environment();

        try {
            foreach (Tenant::query()->cursor() as $tenant) {
                foreach (Environment::cases() as $environment) {
                    $context->bind($tenant, new Actor('system', 'einvoice:consolidate', 'consolidate', ['*']), $environment);

                    try {
                        foreach ($this->issuers($environment) as $issuer) {
                            // One issuer's bad month must not abort the sweep for everyone else.
                            try {
                                foreach ($action->handle($issuer, $month) as $parent) {
                                    $parents++;
                                    $children = data_get($parent->metadata, 'consolidation.children');
                                    $consolidated += is_int($children) ? $children : 0;
                                }
                            } catch (Throwable $e) {
                                report($e);
                                $this->error("Issuer {$issuer->id}: {$e->getMessage()}");
                            }
                        }
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

        $this->info("Consolidated {$consolidated} document(s) into {$parents} invoice(s) for {$month->format('Y-m')}.");

        return self::SUCCESS;
    }

    /** @return LazyCollection<int, Issuer> */
    private function issuers(Environment $environment): LazyCollection
    {
        return Issuer::query()
            ->where('environment', $environment)
            ->where('consolidation_enabled', true)
            ->where('status', IssuerStatus::Active)
            ->cursor();
    }

    /** Null when --month was given but is not a real YYYY-MM. */
    private function month(): ?CarbonImmutable
    {
        $option = $this->option('month');
        if (! is_string($option) || $option === '') {
            return CarbonImmutable::now('Asia/Kuala_Lumpur')->subMonthNoOverflow()->startOfMonth();
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $option) !== 1) {
            return null;
        }

        return CarbonImmutable::parse("{$option}-01", 'Asia/Kuala_Lumpur')->startOfMonth();
    }
}
