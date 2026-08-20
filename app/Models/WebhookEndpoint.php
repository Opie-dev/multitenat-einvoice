<?php

namespace App\Models;

use App\Enums\Environment;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $url
 * @property string $secret
 * @property string[] $events
 * @property bool $enabled
 * @property Environment $environment
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
            'environment' => Environment::class,
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        return $this->enabled && in_array($event, $this->events, true);
    }

    /** @param  Builder<WebhookEndpoint>  $query */
    public function scopeForCurrentEnvironment(Builder $query): void
    {
        $query->where('environment', app(TenantContext::class)->environment());
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::forCurrentEnvironment()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
