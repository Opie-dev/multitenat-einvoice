<?php

namespace App\Data\Requests\Webhooks;

use App\Enums\WebhookEvent;
use Closure;
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
            'url' => ['required', 'string', 'max:500', 'url', self::httpsRule()],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
        ];
    }

    private static function httpsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            $scheme = parse_url($value, PHP_URL_SCHEME);
            $host = parse_url($value, PHP_URL_HOST);
            if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
                $fail('Webhook URLs must use HTTPS.');
            }
        };
    }
}
