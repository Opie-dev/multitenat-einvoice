<?php

namespace App\Domain\Onboarding;

final class StepState
{
    public function __construct(
        public readonly string $key,
        public readonly bool $done,
        public readonly ?string $blocked_reason = null,
    ) {}
}
