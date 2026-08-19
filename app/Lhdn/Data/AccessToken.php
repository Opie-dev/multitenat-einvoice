<?php

namespace App\Lhdn\Data;

use Carbon\CarbonImmutable;

final class AccessToken
{
    public function __construct(public readonly string $token, public readonly CarbonImmutable $expiresAt) {}

    public function isExpired(int $marginSeconds = 0): bool
    {
        return $this->expiresAt->subSeconds($marginSeconds)->isPast();
    }
}
