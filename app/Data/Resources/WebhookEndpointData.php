<?php

namespace App\Data\Resources;

use App\Models\WebhookEndpoint;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class WebhookEndpointData extends Data
{
    /** @param string[] $events */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public bool $enabled,
        public string $environment,
        public ?string $description,
        public ?string $created_at,
        public ?string $updated_at,
        public string|Optional $secret, // plaintext — present only on creation
    ) {}

    public static function fromModel(WebhookEndpoint $endpoint): self
    {
        return new self(
            id: $endpoint->id,
            url: $endpoint->url,
            events: $endpoint->events,
            enabled: $endpoint->enabled,
            environment: $endpoint->environment->value,
            description: $endpoint->description,
            created_at: $endpoint->created_at->toIso8601String(),
            updated_at: $endpoint->updated_at->toIso8601String(),
            secret: Optional::create(),
        );
    }

    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }
}
