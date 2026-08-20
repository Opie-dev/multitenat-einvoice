<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;

class DeleteWebhookEndpoint
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookEndpoint $webhookEndpoint): void
    {
        $this->audit->record('webhook.deleted', $webhookEndpoint);

        $webhookEndpoint->delete();
    }
}
