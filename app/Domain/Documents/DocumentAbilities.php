<?php

namespace App\Domain\Documents;

use App\Actions\Documents\SubmitDocument;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Pdf\DocumentPdfGenerator;

/**
 * Read-only projection of which actions are currently legal on a document,
 * for UI consumption (the web document browser). Every check delegates to
 * the same guards the API endpoints already enforce, so a document's status
 * is never independently re-classified here — this is a view over
 * `DocumentStateMachine` and the existing action guards (referenced via
 * their public constants below, not copied), not a second source of truth.
 */
final class DocumentAbilities
{
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

            // SubmitDocument::RESUBMITTABLE_STATUSES is the single source of
            // truth for which statuses accept a submit/resubmit call. The
            // state machine's `TRANSITIONS` map separately allows
            // `awaiting_consolidation` -> `queued`, but that edge currently
            // has no caller anywhere in the codebase (it is not the
            // consolidation-recovery path — that listener,
            // ReleaseChildrenOnConsolidationFailure, transitions the
            // opposite direction, `consolidated` -> `awaiting_consolidation`)
            // and SubmitDocument's whitelist excludes it regardless, so
            // `canTransition()` here is defense-in-depth (closes the ability
            // if the map ever tightens), not the authority on eligibility.
            'can_resubmit' => in_array($document->status, SubmitDocument::RESUBMITTABLE_STATUSES, true)
                && $stateMachine->canTransition($document->status, DocumentStatus::Queued),

            'can_pdf' => in_array($document->status, DocumentPdfGenerator::AVAILABLE_STATUSES, true)
                && $document->lhdn_uuid !== null,
        ];
    }
}
