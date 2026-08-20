<?php

namespace App\Actions\Webhooks;

use App\Data\Requests\Webhooks\UpdateWebhookEndpointData;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;

class UpdateWebhookEndpoint
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookEndpoint $webhookEndpoint, UpdateWebhookEndpointData $data): WebhookEndpoint
    {
        // Captured before update(): Model::save() syncs the original attributes
        // to the new values before update() returns, so getOriginal() must be
        // snapshotted here to recover the pre-update "from" values for the diff.
        $original = $webhookEndpoint->getOriginal();

        $webhookEndpoint->update($data->toArray());

        $this->audit->record('webhook.updated', $webhookEndpoint, AuditLogger::diff($webhookEndpoint, $original));

        return $webhookEndpoint->refresh();
    }
}
