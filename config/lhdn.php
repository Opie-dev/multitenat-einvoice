<?php

return [
    'driver' => env('LHDN_DRIVER', 'http'), // http | fake

    'environments' => [
        'sandbox' => [
            'api_base' => env('LHDN_SANDBOX_API_BASE', 'https://preprod-api.myinvois.hasil.gov.my'),
            'identity_base' => env('LHDN_SANDBOX_IDENTITY_BASE', 'https://preprod-api.myinvois.hasil.gov.my'),
            'portal_base' => env('LHDN_SANDBOX_PORTAL_BASE', 'https://preprod.myinvois.hasil.gov.my'),
        ],
        'production' => [
            'api_base' => env('LHDN_PRODUCTION_API_BASE', 'https://api.myinvois.hasil.gov.my'),
            'identity_base' => env('LHDN_PRODUCTION_IDENTITY_BASE', 'https://api.myinvois.hasil.gov.my'),
            'portal_base' => env('LHDN_PRODUCTION_PORTAL_BASE', 'https://myinvois.hasil.gov.my'),
        ],
    ],

    'intermediary' => [
        'sandbox' => ['client_id' => env('LHDN_SANDBOX_CLIENT_ID'), 'client_secret' => env('LHDN_SANDBOX_CLIENT_SECRET')],
        'production' => ['client_id' => env('LHDN_PRODUCTION_CLIENT_ID'), 'client_secret' => env('LHDN_PRODUCTION_CLIENT_SECRET')],
    ],

    'timeout' => (int) env('LHDN_TIMEOUT', 30),
    'token_ttl_margin_seconds' => 60,
    'tin_cache_hours' => 24,

    // per issuer, per minute
    'rate_limits' => ['token' => 12, 'submit' => 100, 'get_submission' => 300, 'get_document' => 60, 'cancel' => 12, 'validate_tin' => 60],

    'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_seconds' => 60],

    'submission' => [
        'max_documents' => 100,
        'max_bytes' => 5 * 1024 * 1024,
        'max_document_bytes' => 300 * 1024,
        'max_attempts' => 8,
        'retry_backoff_seconds' => [30, 60, 120, 300, 600, 1200, 1800, 3600],
    ],

    'poll' => ['backoff_seconds' => [5, 15, 30, 60, 120, 300, 600, 1800, 3600]],

    'status_refresh' => [
        'max_age_days' => (int) env('LHDN_STATUS_REFRESH_MAX_AGE_DAYS', 7),
        'interval_hours' => (int) env('LHDN_STATUS_REFRESH_INTERVAL_HOURS', 6),
    ],

    'duplicate_rejection_codes' => ['DUPLICATE_SUBMISSION'],

    // ~7 years, per the LHDN record-keeping requirement (spec §7.5).
    'attempts_retention_days' => (int) env('LHDN_ATTEMPTS_RETENTION_DAYS', 2555),
];
