<?php

namespace App\Models;

use Database\Factories\ServiceTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $token_hash
 * @property string[] $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServiceToken extends Model
{
    /** @use HasFactory<ServiceTokenFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'token_hash', 'abilities', 'last_used_at', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  string[]  $abilities
     * @return array{token: self, plaintext: string}
     */
    public static function generate(string $name, array $abilities = ['*']): array
    {
        $plaintext = 'sk_'.Str::slug($name, '_').'_'.Str::random(40);
        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
            'abilities' => $abilities,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}
