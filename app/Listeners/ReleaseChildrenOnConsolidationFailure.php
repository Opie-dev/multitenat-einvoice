<?php

namespace App\Listeners;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\WebhookEvent;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookPayload;

/**
 * A rejected consolidated invoice must not swallow the receipts it was reporting:
 * they go back to `awaiting_consolidation` so the next monthly run picks them up,
 * and the tenant is told, per receipt, that it happened.
 *
 * `consolidated_into_id` is deliberately left pointing at the failed parent — it
 * is the audit trail of what was attempted; the next run overwrites it.
 */
class ReleaseChildrenOnConsolidationFailure
{
    public function __construct(
        private readonly DocumentStateMachine $stateMachine,
        private readonly WebhookDispatcher $dispatcher,
    ) {}

    public function handle(DocumentTransitioned $event): void
    {
        if ($event->to !== DocumentStatus::Invalid) {
            return;
        }

        $children = Document::query()
            ->where('consolidated_into_id', $event->document->id)
            ->where('status', DocumentStatus::Consolidated)
            ->orderBy('source_ref')
            ->get();

        $name = WebhookEvent::DocumentConsolidationFailed->value;
        foreach ($children as $child) {
            $this->stateMachine->transition($child, DocumentStatus::AwaitingConsolidation, 'consolidation_failed');
            $this->dispatcher->dispatch($name, $child->environment, WebhookPayload::document($name, $child));
        }
    }
}
