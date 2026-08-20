<?php

namespace App\Domain\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;

/**
 * Read-only projection of which actions are currently legal on a document,
 * for UI consumption (the web document browser). Every check delegates to
 * the same guards the API endpoints already enforce, so a document's status
 * is never independently re-classified here — this is a view over
 * `DocumentStateMachine` and the existing action guards, not a second source
 * of truth.
 */
final class DocumentAbilities
{
    /**
     * Statuses `SubmitDocument::handle()` accepts a submit/resubmit call
     * from. The state machine's `TRANSITIONS` map additionally allows
     * `awaiting_consolidation` -> `queued`, but that path belongs to
     * consolidation recovery (`ReleaseChildrenOnConsolidationFailure`), not
     * the merchant-facing submit endpoint, so it is deliberately excluded
     * here even though `canTransition()` alone would allow it.
     *
     * @var list<DocumentStatus>
     */
    private const RESUBMIT_ELIGIBLE_STATUSES = [
        DocumentStatus::Validated,
        DocumentStatus::Held,
        DocumentStatus::Invalid,
    ];

    /**
     * Statuses `DocumentPdfController::show()` will serve a PDF for. Mirrors
     * that controller's `AVAILABLE_STATUSES` guard.
     *
     * @var list<DocumentStatus>
     */
    private const PDF_AVAILABLE_STATUSES = [
        DocumentStatus::Valid,
        DocumentStatus::Cancelled,
        DocumentStatus::Rejected,
    ];

    /** @return array{can_cancel: bool, can_resubmit: bool, can_pdf: bool} */
    public static function for(Document $document): array
    {
        $stateMachine = new DocumentStateMachine;

        return [
            // Only `valid` documents can transition to `cancelled` (mirrors
            // CancelDocument::handle()'s status check); the 72h window and
            // uuid checks mirror that same action's remaining guards.
            'can_cancel' => $stateMachine->canTransition($document->status, DocumentStatus::Cancelled)
                && $document->isCancellable()
                && $document->lhdn_uuid !== null
                && $document->lhdn_uuid !== '',

            'can_resubmit' => in_array($document->status, self::RESUBMIT_ELIGIBLE_STATUSES, true)
                && $stateMachine->canTransition($document->status, DocumentStatus::Queued),

            'can_pdf' => in_array($document->status, self::PDF_AVAILABLE_STATUSES, true)
                && $document->lhdn_uuid !== null,
        ];
    }
}
