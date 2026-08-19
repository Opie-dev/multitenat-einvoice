<?php

namespace App\Data\Resources;

use App\Auth\Actor;
use Spatie\LaravelData\Data;

class ActorData extends Data
{
    /** @param string[] $abilities */
    public function __construct(
        public string $type,
        public string $id,
        public string $name,
        public array $abilities,
    ) {}

    public static function fromActor(Actor $actor): self
    {
        return new self($actor->type, $actor->id, $actor->name, $actor->abilities);
    }
}
