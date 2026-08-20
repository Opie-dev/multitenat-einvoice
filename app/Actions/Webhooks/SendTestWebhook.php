<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Str;

class SendTestWebhook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookEndpoint $webhookEndpoint): WebhookDelivery
    {
        $payload = [
            'id' => (string) Str::ulid(),
            'event' => 'webhook.test',
            'created_at' => now()->toIso8601String(),
            'data' => ['message' => 'Test delivery from Billplz E-Invoice Engine'],
        ];

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhookEndpoint->id,
            'event' => 'webhook.test',
            'payload' => $payload,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt' => 0,
        ]);
        DeliverWebhook::dispatch($delivery->id);

        $this->audit->record('webhook.tested', $webhookEndpoint);

        // On the sync queue DeliverWebhook has already run by the time dispatch()
        // returns, so refresh() picks up its outcome; on a real queue this is a
        // harmless no-op and the caller simply sees the row as still pending.
        return $delivery->refresh();
    }
}
