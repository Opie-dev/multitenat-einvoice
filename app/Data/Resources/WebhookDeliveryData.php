<?php

namespace App\Data\Resources;

use App\Models\WebhookDelivery;
use Spatie\LaravelData\Data;

class WebhookDeliveryData extends Data
{
    public function __construct(
        public string $id,
        public string $webhook_endpoint_id,
        public string $event,
        public string $status,
        public int $attempt,
        public ?int $http_status,
        public ?string $response_snippet,
        public ?string $error_message,
        public ?string $delivered_at,
        public ?string $next_retry_at,
        public ?string $created_at,
    ) {}

    public static function fromModel(WebhookDelivery $delivery): self
    {
        return new self(
            id: $delivery->id,
            webhook_endpoint_id: $delivery->webhook_endpoint_id,
            event: $delivery->event,
            status: $delivery->status->value,
            attempt: $delivery->attempt,
            http_status: $delivery->http_status,
            response_snippet: $delivery->response_snippet,
            error_message: $delivery->error_message,
            delivered_at: $delivery->delivered_at?->toIso8601String(),
            next_retry_at: $delivery->next_retry_at?->toIso8601String(),
            created_at: $delivery->created_at->toIso8601String(),
        );
    }
}
