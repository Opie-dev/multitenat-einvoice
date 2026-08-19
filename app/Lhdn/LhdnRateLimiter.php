<?php

namespace App\Lhdn;

use App\Models\Issuer;
use Illuminate\Support\Facades\RateLimiter;

class LhdnRateLimiter
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function attempt(Issuer $issuer, string $operation, callable $fn): mixed
    {
        $limit = (int) config("lhdn.rate_limits.{$operation}", 60);
        $key = "lhdn:{$operation}:{$issuer->id}";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw LhdnException::transient("LHDN {$operation} rate budget exhausted for issuer {$issuer->id}.", 429);
        }
        RateLimiter::hit($key, 60);

        return $fn();
    }
}
