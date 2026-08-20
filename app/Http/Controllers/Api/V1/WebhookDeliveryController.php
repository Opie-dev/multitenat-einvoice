<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Resources\WebhookDeliveryData;
use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WebhookDeliveryController extends Controller
{
    public function test(WebhookEndpoint $webhookEndpoint, AuditLogger $audit): JsonResponse
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

        $audit->record('webhook.tested', $webhookEndpoint);

        // On the sync queue DeliverWebhook has already run by the time dispatch()
        // returns, so refresh() picks up its outcome; on a real queue this is a
        // harmless no-op and the response simply reports the row as still pending.
        return response()->json(['data' => WebhookDeliveryData::fromModel($delivery->refresh())->toArray()], 202);
    }

    public function redeliver(WebhookDelivery $webhookDelivery, AuditLogger $audit): JsonResponse
    {
        $clone = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhookDelivery->webhook_endpoint_id,
            'event' => $webhookDelivery->event,
            'payload' => $webhookDelivery->payload,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt' => 0,
        ]);
        DeliverWebhook::dispatch($clone->id);

        $audit->record('webhook.redelivered', $clone, ['original_delivery_id' => $webhookDelivery->id]);

        return response()->json(['data' => WebhookDeliveryData::fromModel($clone->refresh())->toArray()], 202);
    }
}
