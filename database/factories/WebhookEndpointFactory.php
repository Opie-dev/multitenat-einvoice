<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Enums\WebhookEvent;
use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'url' => 'https://example.com/webhooks/'.Str::random(8),
            'secret' => 'whsec_'.Str::random(40),
            'events' => [WebhookEvent::DocumentValid->value],
            'enabled' => true,
            'environment' => Environment::Sandbox,
            'description' => null,
        ];
    }
}
