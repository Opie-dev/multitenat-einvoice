<?php

namespace App\Webhooks;

use App\Data\Resources\DocumentData;
use App\Data\Resources\IssuerData;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Support\Str;

/** Builds the envelope every webhook delivery ships: a stable shape regardless of event. */
class WebhookPayload
{
    /** @return array<string, mixed> */
    public static function document(string $event, Document $document): array
    {
        return [
            'id' => (string) Str::ulid(),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => DocumentData::fromModel($document)->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function issuer(string $event, Issuer $issuer, array $extra = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => array_merge(IssuerData::fromModel($issuer)->toArray(), $extra),
        ];
    }
}
