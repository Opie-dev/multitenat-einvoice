<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\Webhooks\CreateWebhookEndpointData;
use App\Data\Requests\Webhooks\UpdateWebhookEndpointData;
use App\Data\Resources\WebhookDeliveryData;
use App\Data\Resources\WebhookEndpointData;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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

    public function store(CreateWebhookEndpointData $data, TenantContext $context, AuditLogger $audit): WebhookEndpointData
    {
        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::create($data->toArray() + [
            'environment' => $context->environment(),
            'secret' => $secret,
        ]);

        $audit->record('webhook.created', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        return WebhookEndpointData::fromModel($endpoint)->withSecret($secret)->wrap('data');
    }

    public function show(WebhookEndpoint $webhookEndpoint): WebhookEndpointData
    {
        return WebhookEndpointData::fromModel($webhookEndpoint)->wrap('data');
    }

    public function update(UpdateWebhookEndpointData $data, WebhookEndpoint $webhookEndpoint, AuditLogger $audit): WebhookEndpointData
    {
        // Captured before update(): Model::save() syncs the original attributes
        // to the new values before update() returns, so getOriginal() must be
        // snapshotted here to recover the pre-update "from" values for the diff.
        $original = $webhookEndpoint->getOriginal();

        $webhookEndpoint->update($data->toArray());

        $audit->record('webhook.updated', $webhookEndpoint, AuditLogger::diff($webhookEndpoint, $original));

        return WebhookEndpointData::fromModel($webhookEndpoint->refresh())->wrap('data');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint, AuditLogger $audit): Response
    {
        $audit->record('webhook.deleted', $webhookEndpoint);

        $webhookEndpoint->delete();

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
