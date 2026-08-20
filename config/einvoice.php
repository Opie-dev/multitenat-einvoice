<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed per credential (spec §3.3). The `api` limiter
    | registered in App\Providers\AppServiceProvider keys on a SHA-256 of the
    | bearer token, falling back to the client IP for unauthenticated calls.
    |
    */

    'rate_limit_per_minute' => (int) env('EINVOICE_RATE_LIMIT_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key TTL
    |--------------------------------------------------------------------------
    |
    | Hours a cached response for an Idempotency-Key is retained (see
    | App\Http\Middleware\IdempotencyKey).
    |
    */

    'idempotency_ttl_hours' => (int) env('EINVOICE_IDEMPOTENCY_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Webhook delivery
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before each retry of a failed delivery (App\Jobs\DeliverWebhook),
    | indexed by attempt number; once the attempt count exceeds the list, the
    | delivery is marked exhausted. `timeout` bounds how long a single HTTP
    | attempt to the merchant's endpoint may take.
    |
    */

    'webhooks' => [
        'backoff_seconds' => [60, 300, 1800, 7200, 21600, 86400],
        'timeout' => (int) env('EINVOICE_WEBHOOK_TIMEOUT', 10),
    ],

];
