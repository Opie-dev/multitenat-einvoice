<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use magic-link token. Only the SHA-256 hash is ever persisted;
 * the plaintext exists only for the duration of one request/mail send.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class LoginToken extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'consumed_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
