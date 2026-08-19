<?php

namespace App\Models;

use App\Enums\Environment;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property Environment $environment
 * @property string[] $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    public const ABILITIES = ['read', 'documents:write', 'issuers:manage', 'webhooks:manage'];

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'prefix', 'key_hash', 'environment', 'abilities', 'last_used_at', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  string[]  $abilities
     * @return array{key: self, plaintext: string}
     */
    public static function generate(Tenant $tenant, string $name, Environment $environment, array $abilities): array
    {
        $plaintext = ($environment === Environment::Production ? 'ek_live_' : 'ek_test_').Str::random(40);
        $key = static::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'prefix' => substr($plaintext, 0, 12),
            'key_hash' => hash('sha256', $plaintext),
            'environment' => $environment,
            'abilities' => array_values($abilities),
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }
}
