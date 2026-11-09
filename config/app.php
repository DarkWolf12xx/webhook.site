<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Tester-specific Configuration
    |--------------------------------------------------------------------------
    */

    // How many requests each URL will log before returning a 410 Gone, and the user
    // will have to create a new URL.
    'max_requests' => (int) env('WEBHOOK_MAX_REQUESTS', 500),

    // Requests and tokens will expire in 7 days (default)
    'expiry' => (int) env('WEBHOOK_EXPIRY', 604800),

    // Content-Security-Policy sent with the custom response of a webhook URL and with
    // the raw view of captured requests. Their content is chosen by third parties and
    // served from this origin, so by default browsers render it sandboxed: no scripts,
    // no access to this site's storage. API clients are not affected by this header.
    // Set to an empty string to disable (not recommended for public instances).
    'webhook_response_csp' => env('WEBHOOK_RESPONSE_CSP', 'sandbox'),

    // How many webhook URLs one client IP may create per minute (0 = unlimited).
    // Every URL is kept in Redis for 'expiry' seconds and can hold 'max_requests'
    // captured requests, so unbounded creation exhausts memory.
    'token_create_rate_limit' => (int) env('WEBHOOK_TOKEN_RATE_LIMIT', 60),

    // Content-Security-Policy of the web UI. "{nonce}" is replaced per response.
    // - connect-src is open because "Redirect" re-sends captured requests from the
    //   browser to any URL the user configures (and Pusher/socket.io may use another
    //   host or port);
    // - buttons.github.io serves the GitHub star button;
    // - 'unsafe-inline' styles are needed by AngularJS/Bootstrap widgets.
    'ui_csp' => env('WEBHOOK_UI_CSP', implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{nonce}' https://buttons.github.io",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: https:",
        "font-src 'self' data:",
        'connect-src *',
        'frame-src https://buttons.github.io',
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ])),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'Webhook.site'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services your application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    // Off unless explicitly enabled: debug mode adds exception class, message, file,
    // line and stack trace to every error response.
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone / Locale
    |--------------------------------------------------------------------------
    */

    'timezone' => 'UTC',

    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    */

    'providers' => ServiceProvider::defaultProviders()->merge([
        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\BroadcastServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    */

    'aliases' => Facade::defaultAliases()->toArray(),

];
