<?php

namespace App\Auth;

final class Actor
{
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
