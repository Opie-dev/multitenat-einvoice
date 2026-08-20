<?php

namespace App\Data\Requests\Webhooks;

use App\Enums\WebhookEvent;
use App\Rules\PublicHttpsUrl;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class UpdateWebhookEndpointData extends Data
{
    /** @param list<string>|Optional $events */
    public function __construct(
        public string|Optional $url,
        public array|Optional $events,
        #[Max(255)] public string|Optional|null $description,
        public bool|Optional $enabled,
    ) {}

    /**
     * Rule::in and the PublicHttpsUrl rule below cannot be expressed as PHP
     * attributes, so — like CreateWebhookEndpointData — they must be restated
     * here. Neither rule carries 'required': Laravel skips non-required rules
     * for keys entirely absent from the payload, which is exactly the PATCH
     * semantics an all-Optional DTO needs, while still validating the value
     * when the key is present.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'url' => ['string', 'max:500', 'url', new PublicHttpsUrl],
            'events' => ['array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
        ];
    }
}
