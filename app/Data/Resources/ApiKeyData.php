<?php

namespace App\Data\Resources;

use App\Models\ApiKey;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ApiKeyData extends Data
{
    /** @param string[] $abilities */
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public string $environment,
        public array $abilities,
        public ?string $last_used_at,
        public ?string $created_at,
        public string|Optional $key, // plaintext — present only on creation
    ) {}

    public static function fromModel(ApiKey $key): self
    {
        return new self(
            id: $key->id,
            name: $key->name,
            prefix: $key->prefix,
            environment: $key->environment->value,
            abilities: $key->abilities,
            last_used_at: $key->last_used_at?->toIso8601String(),
            created_at: $key->created_at->toIso8601String(),
            key: Optional::create(),
        );
    }

    public function withPlaintext(string $plaintext): self
    {
        $this->key = $plaintext;

        return $this;
    }
}
