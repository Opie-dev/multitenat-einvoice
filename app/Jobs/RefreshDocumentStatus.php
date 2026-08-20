<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Models\Document;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Asks LHDN what became of one already-`valid` document, catching buyer
 * rejections and LHDN-side cancellations that a poll never happened to see.
 * Driven by the `einvoice:lhdn-dispatch` sweep, not by the submission
 * pipeline: once a document is valid, nothing else re-checks it.
 */
class RefreshDocumentStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public readonly string $documentId)
    {
        $this->captureTenantContext();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            ...$this->tenantMiddleware(),
            (new WithoutOverlapping("lhdn-refresh:{$this->documentId}"))->dontRelease()->expireAfter(120),
        ];
    }

    public function handle(LhdnClientFactory $clients, DocumentStateMachine $stateMachine): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null || $document->status !== DocumentStatus::Valid || $document->lhdn_uuid === null) {
            return;
        }

        try {
            $details = $clients->for($document->issuer)->getDocument($document->issuer, $document->lhdn_uuid);
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Terminal && $e->httpStatus === 404) {
                // The document is unknown to LHDN — a permanent answer for this cycle,
                // not a transient failure to retry sooner.
                $document->forceFill(['lhdn_refreshed_at' => now()])->save();
            }

            return; // any other failure: the sweep retries this document later
        }

        match (strtolower($details->status)) {
            'rejected' => $stateMachine->applyLhdnVerdict($document, DocumentStatus::Rejected, 'buyer_rejected'),
            'cancelled' => $stateMachine->applyLhdnVerdict($document, DocumentStatus::Cancelled, 'cancelled_at_lhdn'),
            default => null,
        };

        $document->forceFill(['lhdn_refreshed_at' => now()])->save();
    }
}
