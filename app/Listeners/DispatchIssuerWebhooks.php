<?php

namespace App\Listeners;

use App\Events\IssuerStatusChanged;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookPayload;

class DispatchIssuerWebhooks
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(IssuerStatusChanged $event): void
    {
        $payload = WebhookPayload::issuer('issuer.status_changed', $event->issuer, [
            'status_from' => $event->from->value,
            'status_to' => $event->to->value,
        ]);

        $this->dispatcher->dispatch('issuer.status_changed', $event->issuer->environment, $payload);
    }
}
