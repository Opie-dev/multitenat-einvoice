<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\Pipeline\SubmissionErrors;
use App\Models\Document;
use App\Models\Issuer;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Asks LHDN what became of one submission and settles the documents in it.
 *
 * Like SubmitDocuments this runs with `tries = 1`: the retry curve lives in
 * `lhdn.poll.backoff_seconds` and is walked by re-dispatching with an
 * incremented `$attempt`. Once the curve is exhausted the job stops and the
 * `einvoice:lhdn-dispatch` sweep picks the submission up again later.
 */
class PollSubmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(
        public readonly string $issuerId,
        public readonly string $submissionUid,
        public readonly int $attempt = 0,
    ) {
        $this->captureTenantContext();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            ...$this->tenantMiddleware(),
            // The sweep and the pipeline can both aim at the same submission; the
            // loser is dropped rather than queued behind the winner.
            (new WithoutOverlapping("lhdn-poll:{$this->submissionUid}"))->dontRelease()->expireAfter(120),
        ];
    }

    public function handle(LhdnClientFactory $clients, DocumentStateMachine $stateMachine): void
    {
        $issuer = Issuer::query()->find($this->issuerId);
        if ($issuer === null) {
            return;
        }

        $pending = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('lhdn_submission_uid', $this->submissionUid)
            ->whereIn('status', [DocumentStatus::Submitted, DocumentStatus::Valid])
            ->get();
        if ($pending->isEmpty()) {
            return;
        }

        /** @var array<string, Document> $byUuid */
        $byUuid = [];
        foreach ($pending as $document) {
            $byUuid[(string) $document->lhdn_uuid] = $document;
        }

        try {
            $status = $clients->for($issuer)->getSubmission($issuer, $this->submissionUid);
        } catch (LhdnException $e) {
            $this->handleFailure($byUuid, $e, $issuer, $clients, $stateMachine);

            return;
        }

        foreach ($status->documents as $summary) {
            $document = $byUuid[$summary->uuid] ?? null;
            if ($document !== null) {
                $this->apply($document, $summary, $issuer, $clients, $stateMachine);
            }
        }

        $stillSubmitted = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('lhdn_submission_uid', $this->submissionUid)
            ->where('status', DocumentStatus::Submitted)
            ->exists();
        if (! $status->isFinal() || $stillSubmitted) {
            $this->reschedule();
        }
    }

    private function apply(Document $document, DocumentSummary $summary, Issuer $issuer, LhdnClientFactory $clients, DocumentStateMachine $stateMachine): void
    {
        $state = strtolower($summary->status);

        if ($document->status === DocumentStatus::Submitted) {
            if ($state === 'valid') {
                $document->forceFill(['lhdn_long_id' => $summary->longId])->save();
                $stateMachine->transition($document, DocumentStatus::Valid);

                return;
            }
            if ($state === 'invalid' || $state === 'cancelled') {
                // A document cancelled before we ever saw it valid never became a
                // live e-invoice, so it settles as invalid rather than cancelled.
                $errors = $this->errorsFor($summary, $state, $issuer, $clients);
                $document->forceFill(['lhdn_errors' => $errors, 'lhdn_long_id' => $summary->longId])->save();
                $stateMachine->transition($document, DocumentStatus::Invalid, 'rejected_by_lhdn', ['errors' => $errors]);
            }

            return;
        }

        // Post-valid states (buyer rejection / LHDN-side cancellation) are only
        // detected here when a poll happens to see them; RefreshDocumentStatus
        // (Plan 4) sweeps for the rest. Both share applyLhdnVerdict(), which is
        // authoritative over the local cancellation window.
        if ($state === 'rejected') {
            $stateMachine->applyLhdnVerdict($document, DocumentStatus::Rejected, 'buyer_rejected');
        } elseif ($state === 'cancelled') {
            $stateMachine->applyLhdnVerdict($document, DocumentStatus::Cancelled, 'cancelled_at_lhdn');
        }
    }

    /** @return non-empty-list<array<string, mixed>> */
    private function errorsFor(DocumentSummary $summary, string $state, Issuer $issuer, LhdnClientFactory $clients): array
    {
        $errors = $summary->errors;
        if ($errors === [] && $state === 'invalid') {
            try {
                $errors = $clients->for($issuer)->getDocument($issuer, $summary->uuid)->validationErrors;
            } catch (LhdnException $e) {
                $errors = SubmissionErrors::fromException($e);
            }
        }
        if ($errors === []) {
            return [[
                'code' => $state === 'cancelled' ? 'CANCELLED_AT_LHDN' : 'INVALID',
                'message' => "LHDN reported the document as {$summary->status}.",
            ]];
        }

        return $errors;
    }

    /**
     * A failure to *read* the submission is never a verdict on the documents in
     * it: a 404/405/409 from `getSubmission` says something about our request or
     * about MyInvois, not about whether the invoices are valid. So every kind
     * except `auth` simply walks the poll curve, and only when the curve is
     * exhausted do we ask LHDN about each document individually.
     *
     * @param  array<string, Document>  $byUuid
     */
    private function handleFailure(array $byUuid, LhdnException $e, Issuer $issuer, LhdnClientFactory $clients, DocumentStateMachine $stateMachine): void
    {
        if ($e->kind === LhdnErrorKind::Auth) {
            return; // credentials are an issuer problem; the sweep retries once they are fixed
        }
        if ($this->reschedule()) {
            return;
        }

        foreach ($byUuid as $uuid => $document) {
            if ($document->status !== DocumentStatus::Submitted || $uuid === '') {
                continue;
            }
            try {
                $details = $clients->for($issuer)->getDocument($issuer, $uuid);
            } catch (LhdnException) {
                continue; // still unknown; the sweep re-polls this submission later
            }
            $summary = new DocumentSummary($uuid, (string) $document->lhdn_internal_id, $details->longId, $details->status, $details->validationErrors);
            $this->apply($document, $summary, $issuer, $clients, $stateMachine);
        }
    }

    /** @return bool whether another poll was queued (false once the curve is spent) */
    private function reschedule(): bool
    {
        $backoffs = array_values(array_map(intval(...), (array) config('lhdn.poll.backoff_seconds', [5])));
        $next = $this->attempt + 1;
        if ($next >= count($backoffs)) {
            return false; // give up for now; einvoice:lhdn-dispatch will poll again
        }

        self::dispatch($this->issuerId, $this->submissionUid, $next)->delay(now()->addSeconds($backoffs[$next]));

        return true;
    }
}
