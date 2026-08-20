<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Passwordless dashboard user (spec 2026-08-20-onboarding-dashboard-design.md
 * §4.1). No password column, ever — sign-in is by magic link only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $issuer_id
 * @property Carbon $invited_at
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Issuer|null $issuer
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'email', 'role', 'issuer_id', 'invited_at', 'last_login_at'];

    /**
     * No password/remember_token columns exist on this model, so there is
     * nothing secret to hide here.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    /** @return HasMany<LoginToken, $this> */
    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isMember(): bool
    {
        return $this->role === UserRole::Member;
    }

    public function isVendor(): bool
    {
        return $this->role === UserRole::Vendor;
    }

    /** An invited user becomes active the first time they consume a magic link. */
    public function isActive(): bool
    {
        return $this->last_login_at !== null;
    }
}
