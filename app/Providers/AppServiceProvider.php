<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // spec 3.3: throttle per credential, not per IP, so one merchant's
        // traffic cannot exhaust another's budget behind a shared NAT. The
        // bearer token is hashed so it never reaches the cache store or logs.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('einvoice.rate_limit_per_minute', 60))
            ->by($request->bearerToken() !== null
                ? 'cred:'.hash('sha256', (string) $request->bearerToken())
                : 'ip:'.$request->ip()));
    }
}
