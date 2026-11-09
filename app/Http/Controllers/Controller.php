<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Headers for responses whose body is controlled by third parties: the custom
     * response of a webhook URL (chosen by whoever created the URL) and the raw view
     * of a captured request (chosen by whoever sent it). Both are served from the
     * application's own origin, so a browser must never run them as part of it:
     * the CSP sandbox gives them an opaque origin (no access to the viewer's
     * localStorage, which holds the user's webhook URLs) and disables scripts.
     *
     * @return array<string, string>
     */
    protected static function untrustedContentHeaders(): array
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $csp = (string) config('app.webhook_response_csp');

        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        return $headers;
    }

    protected static function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, PUT, PATCH, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range',
            'Access-Control-Expose-Headers' => 'Content-Length,Content-Range',
        ];
    }
}
