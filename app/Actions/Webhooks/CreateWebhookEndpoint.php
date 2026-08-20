<?php

namespace App\Actions\Webhooks;

use App\Data\Requests\Webhooks\CreateWebhookEndpointData;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

class CreateWebhookEndpoint
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @return array{endpoint: WebhookEndpoint, secret: string} */
    public function handle(CreateWebhookEndpointData $data): array
    {
        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::create($data->toArray() + [
            'environment' => $this->context->environment(),
            'secret' => $secret,
        ]);

        $this->audit->record('webhook.created', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }
}
