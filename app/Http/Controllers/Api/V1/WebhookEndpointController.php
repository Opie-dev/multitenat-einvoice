<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Actions\Webhooks\UpdateWebhookEndpoint;
use App\Data\Requests\Webhooks\CreateWebhookEndpointData;
use App\Data\Requests\Webhooks\UpdateWebhookEndpointData;
use App\Data\Resources\WebhookDeliveryData;
use App\Data\Resources\WebhookEndpointData;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Response;
use Spatie\LaravelData\CursorPaginatedDataCollection;

class WebhookEndpointController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, WebhookEndpointData> */
    public function index(): CursorPaginatedDataCollection
    {
        return WebhookEndpointData::collect(
            WebhookEndpoint::forCurrentEnvironment()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50),
            CursorPaginatedDataCollection::class,
        );
    }

    public function store(CreateWebhookEndpointData $data, CreateWebhookEndpoint $action): WebhookEndpointData
    {
        ['endpoint' => $endpoint, 'secret' => $secret] = $action->handle($data);

        return WebhookEndpointData::fromModel($endpoint)->withSecret($secret)->wrap('data');
    }

    public function show(WebhookEndpoint $webhookEndpoint): WebhookEndpointData
    {
        return WebhookEndpointData::fromModel($webhookEndpoint)->wrap('data');
    }

    public function update(UpdateWebhookEndpointData $data, WebhookEndpoint $webhookEndpoint, UpdateWebhookEndpoint $action): WebhookEndpointData
    {
        return WebhookEndpointData::fromModel($action->handle($webhookEndpoint, $data))->wrap('data');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint, DeleteWebhookEndpoint $action): Response
    {
        $action->handle($webhookEndpoint);

        return response()->noContent();
    }

    /** @return CursorPaginatedDataCollection<int, WebhookDeliveryData> */
    public function deliveries(WebhookEndpoint $webhookEndpoint): CursorPaginatedDataCollection
    {
        return WebhookDeliveryData::collect(
            $webhookEndpoint->deliveries()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50),
            CursorPaginatedDataCollection::class,
        );
    }
}
