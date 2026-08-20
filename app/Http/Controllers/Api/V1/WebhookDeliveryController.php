<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Webhooks\RedeliverWebhook;
use App\Actions\Webhooks\SendTestWebhook;
use App\Data\Resources\WebhookDeliveryData;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;

class WebhookDeliveryController extends Controller
{
    public function test(WebhookEndpoint $webhookEndpoint, SendTestWebhook $action): JsonResponse
    {
        return response()->json(['data' => WebhookDeliveryData::fromModel($action->handle($webhookEndpoint))->toArray()], 202);
    }

    public function redeliver(WebhookDelivery $webhookDelivery, RedeliverWebhook $action): JsonResponse
    {
        return response()->json(['data' => WebhookDeliveryData::fromModel($action->handle($webhookDelivery))->toArray()], 202);
    }
}
