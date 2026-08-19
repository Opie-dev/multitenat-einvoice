<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\Pipeline\SubmissionErrors;
use App\Models\Document;
use App\Models\Issuer;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;

/**
 * Batches one issuer's prepared documents into a single MyInvois submission.
 *
 * `tries` is 1 on purpose: retries are a business decision recorded on each
 * document (`submission_attempts_count` / `next_submission_at`) and re-driven by
 * this job re-dispatching itself or by `einvoice:lhdn-dispatch` — not by the
 * queue worker, which would replay the whole batch blindly.
 */
class SubmitDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public readonly string $issuerId)
    {
        $this->captureTenantContext();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            ...$this->tenantMiddleware(),
            // dontRelease, not releaseAfter: with tries = 1 a release would burn the
            // job's only attempt and fail it before handle() ever runs. Dropping the
            // overlapping dispatch is safe — the winner's tail re-dispatch and the
            // einvoice:lhdn-dispatch sweep both pick the remaining documents back up.
            (new WithoutOverlapping("lhdn-submit:{$this->issuerId}"))->dontRelease()->expireAfter(300),
        ];
    }

    public function handle(LhdnClientFactory $clients, DocumentStateMachine $stateMachine): void
    {
        $issuer = Issuer::query()->find($this->issuerId);
        if ($issuer === null) {
            return;
        }

        $documents = $this->eligible($issuer);
        if ($documents->isEmpty()) {
            return;
        }

        /** @var list<SubmissionDocument> $payloads */
        $payloads = [];
        foreach ($documents as $document) {
            $payloads[] = SubmissionDocument::fromJson((string) $document->lhdn_internal_id, (string) $document->ubl_json);
        }
        $batch = new SubmissionBatch($payloads);

        try {
            $result = $clients->for($issuer)->submitDocuments($issuer, $batch);
        } catch (LhdnException $e) {
            $this->handleFailure($documents, $e, $stateMachine);

            return;
        }

        $settled = 0;
        foreach ($documents as $document) {
            $internalId = (string) $document->lhdn_internal_id;
            if (isset($result->acceptedUuidsByInternalId[$internalId]) || isset($result->rejectedByInternalId[$internalId])) {
                $settled++;
            }
            if (isset($result->acceptedUuidsByInternalId[$internalId])) {
                $document->forceFill([
                    'lhdn_uuid' => $result->acceptedUuidsByInternalId[$internalId],
                    'lhdn_submission_uid' => $result->submissionUid,
                    'submission_attempts_count' => $document->submission_attempts_count + 1,
                    'last_submission_error' => null,
                    'next_submission_at' => null,
                ])->save();
                $stateMachine->transition($document, DocumentStatus::Submitted);
            } elseif (isset($result->rejectedByInternalId[$internalId])) {
                $errors = SubmissionErrors::fromRejection($result->rejectedByInternalId[$internalId]);
                $document->forceFill([
                    'lhdn_errors' => $errors,
                    'submission_attempts_count' => $document->submission_attempts_count + 1,
                    'next_submission_at' => null,
                ])->save();
                $stateMachine->transition($document, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => $errors]);
            }
        }

        if ($result->acceptedUuidsByInternalId !== []) {
            PollSubmission::dispatch($issuer->id, $result->submissionUid)->delay(now()->addSeconds($this->firstPollDelay()));
        }
        // Only chase the next batch when this one moved something: a reply that
        // acknowledges none of our documents would otherwise re-dispatch forever.
        // The scheduler sweep picks those documents up a minute later instead.
        if ($settled > 0 && $this->eligible($issuer)->isNotEmpty()) {
            self::dispatch($issuer->id);
        }
    }

    /**
     * Oldest first, capped by both the document count and the total payload size
     * MyInvois accepts in one call — measured base64-encoded, the way LHDN counts
     * it. A single document larger than the byte cap is still sent alone;
     * PrepareDocument already rejects the truly oversized ones.
     *
     * @return Collection<int, Document>
     */
    private function eligible(Issuer $issuer): Collection
    {
        $maxBytes = (int) config('lhdn.submission.max_bytes', 5 * 1024 * 1024);

        $candidates = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('status', DocumentStatus::Queued)
            ->whereNotNull('ubl_json')
            ->where(fn (Builder $query) => $query->whereNull('next_submission_at')->orWhere('next_submission_at', '<=', now()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit((int) config('lhdn.submission.max_documents', 100))
            ->get();

        $bytes = 0;
        /** @var Collection<int, Document> $batch */
        $batch = collect();
        foreach ($candidates as $document) {
            $size = SubmissionDocument::fromJson((string) $document->lhdn_internal_id, (string) $document->ubl_json)->wireSizeBytes();
            if ($batch->isNotEmpty() && $bytes + $size > $maxBytes) {
                break;
            }
            $bytes += $size;
            $batch->push($document);
        }

        return $batch;
    }

    /** @param  Collection<int, Document>  $documents */
    private function handleFailure(Collection $documents, LhdnException $e, DocumentStateMachine $stateMachine): void
    {
        $summary = SubmissionErrors::summary($e);
        $maxAttempts = (int) config('lhdn.submission.max_attempts', 8);
        $backoffs = $this->seconds('lhdn.submission.retry_backoff_seconds', [60]);
        $retryIn = null;

        foreach ($documents as $document) {
            $attempts = $document->submission_attempts_count + 1;

            if ($e->kind === LhdnErrorKind::Auth) {
                $document->forceFill(['last_submission_error' => $summary])->save();
                $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::LhdnCredentialsInvalid);

                continue;
            }
            if ($e->kind === LhdnErrorKind::Terminal) {
                $errors = SubmissionErrors::fromException($e);
                $document->forceFill(['last_submission_error' => $summary, 'lhdn_errors' => $errors, 'submission_attempts_count' => $attempts])->save();
                $stateMachine->transition($document, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => $errors]);

                continue;
            }
            if ($attempts >= $maxAttempts) {
                $document->forceFill(['last_submission_error' => $summary, 'submission_attempts_count' => $attempts])->save();
                $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);

                continue;
            }

            $seconds = $backoffs[min($attempts - 1, count($backoffs) - 1)];
            $retryIn = max($retryIn ?? 0, $seconds);
            $document->forceFill([
                'last_submission_error' => $summary,
                'submission_attempts_count' => $attempts,
                'next_submission_at' => now()->addSeconds($seconds),
            ])->save();
        }

        if ($retryIn !== null) {
            self::dispatch($this->issuerId)->delay(now()->addSeconds($retryIn));
        }
    }

    private function firstPollDelay(): int
    {
        return $this->seconds('lhdn.poll.backoff_seconds', [5])[0];
    }

    /**
     * @param  non-empty-list<int>  $default
     * @return non-empty-list<int>
     */
    private function seconds(string $key, array $default): array
    {
        $configured = array_values(array_map(intval(...), (array) config($key, $default)));

        return $configured === [] ? $default : $configured;
    }
}
