<?php

namespace App\Webhooks;

use App\Enums\Environment;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Fans a domain event out to every enabled endpoint of the current tenant that
 * listens to it, for the matching environment. Each match gets its own pending
 * delivery row and its own DeliverWebhook job — never a shared retry budget.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     * @return int Number of deliveries created.
     */
    public function dispatch(string $event, Environment $environment, array $payload): int
    {
        $endpoints = WebhookEndpoint::query()
            ->where('enabled', true)
            ->where('environment', $environment)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->listensTo($event));

        $created = 0;
        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDeliveryStatus::Pending,
                'attempt' => 0,
            ]);
            DeliverWebhook::dispatch($delivery->id);
            $created++;
        }

        return $created;
    }
}
