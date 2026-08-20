<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempt
 * @property int|null $http_status
 * @property string|null $response_snippet
 * @property string|null $error_message
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_retry_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'attempt' => 'integer',
            'http_status' => 'integer',
            'delivered_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Deliveries carry no environment column (only their endpoint does), so the
     * tenant global scope alone is enough to keep this cross-tenant safe; stated
     * explicitly here rather than relying on Eloquent's implicit default.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
