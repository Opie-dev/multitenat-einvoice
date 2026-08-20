<?php

namespace App\Listeners;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Events\IssuerStatusChanged;
use App\Models\Document;

/**
 * Spec §7.4: suspending an issuer moves its documents to `held`. A suspended
 * issuer cannot submit anything, so everything still waiting in the queue is
 * parked with `certificate_expired` — the one held reason that is released again
 * when the issuer re-activates (see ReleaseHeldDocuments).
 *
 * `IssuerStatusChanged` carries no suspension reason, and the only path that
 * suspends an issuer today is a lapsed certificate (IssuerActivator::evaluate),
 * so every suspension holds. Documents already handed to LHDN (`submitted`) are
 * left alone: they cannot be recalled and LHDN will still answer for them.
 *
 * Streamed by id so a busy issuer's backlog is never fully materialised, and so
 * transitioning rows out of `queued` cannot make the walk skip a page.
 */
class HoldDocumentsOnSuspension
{
    private const CHUNK = 500;

    public function __construct(private readonly DocumentStateMachine $stateMachine) {}

    public function handle(IssuerStatusChanged $event): void
    {
        if ($event->to !== IssuerStatus::Suspended) {
            return;
        }

        $documents = Document::query()
            ->where('issuer_id', $event->issuer->id)
            ->where('status', DocumentStatus::Queued)
            ->lazyById(self::CHUNK);

        foreach ($documents as $document) {
            $this->stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);
        }
    }
}
