<?php

namespace App\Console\Commands;

use App\Models\SubmissionAttempt;
use Illuminate\Console\Command;

/**
 * Deletes `submission_attempts` rows older than the retention window
 * (spec §7.5: 7 years by default, `config('lhdn.attempts_retention_days')`).
 * Documents themselves are never pruned; only the raw LHDN exchange log.
 */
class PruneSubmissionAttempts extends Command
{
    protected $signature = 'einvoice:prune-attempts {--days= : Retention window in days; defaults to config(lhdn.attempts_retention_days)}';

    protected $description = 'Delete submission_attempts rows older than the retention window.';

    private const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('lhdn.attempts_retention_days'));
        $cutoff = now()->subDays($days);

        $deleted = 0;
        do {
            // System maintenance job pruning across every tenant's attempts, not
            // one tenant's — the CLAUDE.md tenancy rule's "system jobs" carve-out
            // for withoutGlobalScopes() outside credential resolution applies here.
            $affected = SubmissionAttempt::withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $deleted += $affected;
        } while ($affected > 0);

        $this->info("Pruned {$deleted} submission attempt(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
