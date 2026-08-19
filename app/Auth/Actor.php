<?php

namespace App\Auth;

/**
 * @phpstan-type ActorType 'service'|'api_key'|'system'
 */
final class Actor
{
    // 'system' identifies the middleware-bound actor for tenant-aware queued jobs (see App\Tenancy\Jobs\BindTenantContext).
    /** @param string[] $abilities */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $name,
        public readonly array $abilities,
    ) {}

    public function hasAbility(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function label(): string
    {
        return "{$this->type}:{$this->name}";
    }
}
