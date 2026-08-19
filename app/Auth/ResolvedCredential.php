<?php

namespace App\Auth;

use App\Enums\Environment;
use App\Models\Tenant;
use Closure;

final class ResolvedCredential
{
    public function __construct(
        public readonly Actor $actor,
        public readonly ?Tenant $tenant,
        public readonly ?Environment $environment,
        private readonly Closure $touch,
    ) {}

    public function touch(): void
    {
        ($this->touch)();
    }
}
