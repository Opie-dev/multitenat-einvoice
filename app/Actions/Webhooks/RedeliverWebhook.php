<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditLogger;

class RedeliverWebhook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookDelivery $webhookDelivery): WebhookDelivery
    {
        $clone = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhookDelivery->webhook_endpoint_id,
            'event' => $webhookDelivery->event,
            'payload' => $webhookDelivery->payload,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt' => 0,
        ]);
        DeliverWebhook::dispatch($clone->id);

        $this->audit->record('webhook.redelivered', $clone, ['original_delivery_id' => $webhookDelivery->id]);

        return $clone->refresh();
    }
}
