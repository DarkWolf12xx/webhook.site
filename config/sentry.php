<?php

/**
 * Sentry Laravel SDK configuration (sentry/sentry-laravel 4.x).
 * Options not listed here fall back to the package defaults.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    // Error reporting is disabled unless a DSN is configured.
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT'),

    // Do not attach personally identifiable information (IP addresses, cookies,
    // request bodies) to error reports by default.
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

];
