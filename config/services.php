<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenSSL configuration file override
    |--------------------------------------------------------------------------
    |
    | Some Windows PHP builds resolve the openssl extension's default config
    | at module init time, so openssl_pkey_export() can fail with a
    | "configuration file routines" error even when OPENSSL_CONF is set in
    | the environment. App\Services\Certificates\CertificateParser passes
    | this path explicitly to sidestep that timing issue. Unset in
    | production/Linux, where the system default config resolves correctly.
    |
    */
    'openssl_conf' => env('OPENSSL_CONF'),

];
