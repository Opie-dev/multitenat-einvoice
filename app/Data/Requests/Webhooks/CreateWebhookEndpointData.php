<?php

namespace App\Data\Requests\Webhooks;

use App\Enums\WebhookEvent;
use App\Rules\PublicHttpsUrl;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateWebhookEndpointData extends Data
{
    /** @param list<string> $events */
    public function __construct(
        public string $url,
        public array $events,
        #[Max(255)] public ?string $description = null,
        public bool $enabled = true,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'url' => ['required', 'string', 'max:500', 'url', new PublicHttpsUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
        ];
    }
}
