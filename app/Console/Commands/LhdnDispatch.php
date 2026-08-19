<?php

namespace App\Console\Commands;

use App\Auth\Actor;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\PollSubmission;
use App\Jobs\PrepareDocument;
use App\Jobs\SubmitDocuments;
use App\Models\Document;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Safety net for the submission pipeline: whatever the event-driven chain
 * dropped (a worker died mid-prepare, a poll ran out of backoff, LHDN was down
 * when the retry fired) is found here and re-dispatched.
 *
 * This is a system job: it walks every tenant itself and binds a `system` actor
 * per tenant and environment so the usual tenant scoping still applies.
 */
class LhdnDispatch extends Command
{
    protected $signature = 'einvoice:lhdn-dispatch';

    protected $description = 'Sweep for documents that need preparing, submitting or polling and dispatch the pipeline jobs.';

    /** A prepare that never landed is only retried once the document has had a moment to be handled normally. */
    private const LOST_PREPARE_AFTER_MINUTES = 1;

    private const STALE_POLL_AFTER_MINUTES = 2;

    public function handle(TenantContext $context): int
    {
        $dispatched = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            foreach (Environment::cases() as $environment) {
                $context->bind($tenant, new Actor('system', 'einvoice:lhdn-dispatch', 'lhdn-dispatch', ['*']), $environment);

                try {
                    $dispatched += $this->sweep($environment);
                } finally {
                    $context->clear();
                }
            }
        }

        $this->info("Dispatched {$dispatched} job(s).");

        return self::SUCCESS;
    }

    private function sweep(Environment $environment): int
    {
        $dispatched = 0;
        $base = fn (): Builder => Document::query()->where('environment', $environment);

        $issuerIds = $base()
            ->where('status', DocumentStatus::Queued)
            ->whereNotNull('ubl_json')
            ->where(fn (Builder $query) => $query->whereNull('next_submission_at')->orWhere('next_submission_at', '<=', now()))
            ->distinct()
            ->pluck('issuer_id');
        foreach ($issuerIds as $issuerId) {
            SubmitDocuments::dispatch((string) $issuerId);
            $dispatched++;
        }

        $unprepared = $base()
            ->where('status', DocumentStatus::Queued)
            ->whereNull('ubl_json')
            ->where('created_at', '<=', now()->subMinutes(self::LOST_PREPARE_AFTER_MINUTES))
            ->pluck('id');
        foreach ($unprepared as $documentId) {
            PrepareDocument::dispatch((string) $documentId);
            $dispatched++;
        }

        $stale = $base()
            ->where('status', DocumentStatus::Submitted)
            ->whereNotNull('lhdn_submission_uid')
            ->where('submitted_at', '<=', now()->subMinutes(self::STALE_POLL_AFTER_MINUTES))
            ->distinct()
            ->get(['issuer_id', 'lhdn_submission_uid']);
        foreach ($stale as $document) {
            PollSubmission::dispatch($document->issuer_id, (string) $document->lhdn_submission_uid);
            $dispatched++;
        }

        return $dispatched;
    }
}
