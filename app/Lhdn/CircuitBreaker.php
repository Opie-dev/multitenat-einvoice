<?php

namespace App\Lhdn;

use App\Enums\Environment;
use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    public function isOpen(Environment $env): bool
    {
        return Cache::has($this->openKey($env));
    }

    public function guard(Environment $env): void
    {
        if ($this->isOpen($env)) {
            throw LhdnException::breaker("LHDN circuit breaker is open for {$env->value}; retry after cooldown.");
        }
    }

    public function recordFailure(Environment $env): void
    {
        $threshold = (int) config('lhdn.circuit_breaker.failure_threshold', 5);
        $cooldown = (int) config('lhdn.circuit_breaker.cooldown_seconds', 60);
        $failures = (int) Cache::increment($this->countKey($env));
        if ($failures === 1) {
            Cache::put($this->countKey($env), 1, now()->addSeconds($cooldown * 2));
        }
        if ($failures >= $threshold) {
            Cache::put($this->openKey($env), true, now()->addSeconds($cooldown));
            Cache::forget($this->countKey($env));
        }
    }

    public function recordSuccess(Environment $env): void
    {
        Cache::forget($this->countKey($env));
        Cache::forget($this->openKey($env));
    }

    private function openKey(Environment $env): string
    {
        return "lhdn:breaker:open:{$env->value}";
    }

    private function countKey(Environment $env): string
    {
        return "lhdn:breaker:failures:{$env->value}";
    }
}
