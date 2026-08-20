<?php

namespace App\Listeners;

use App\Events\DocumentTransitioned;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookPayload;

class DispatchDocumentWebhooks
{
    /** @var array<string, string> Document status value => webhook event name. `awaiting_consolidation` intentionally emits nothing. */
    private const EVENT_NAMES = [
        'validated' => 'document.validated',
        'held' => 'document.held',
        'queued' => 'document.queued',
        'submitted' => 'document.submitted',
        'valid' => 'document.valid',
        'invalid' => 'document.invalid',
        'cancelled' => 'document.cancelled',
        'rejected' => 'document.rejected',
        'consolidated' => 'document.consolidated',
    ];

    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(DocumentTransitioned $event): void
    {
        $name = self::EVENT_NAMES[$event->to->value] ?? null;
        if ($name === null) {
            return;
        }

        $this->dispatcher->dispatch($name, $event->document->environment, WebhookPayload::document($name, $event->document));
    }
}
