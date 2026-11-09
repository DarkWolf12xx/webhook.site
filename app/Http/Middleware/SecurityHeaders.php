<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every response produced by the application.
 * Responses that need more (the web UI, sandboxed webhook responses) set their
 * own headers, which are left untouched here.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $defaults = [
            // Never let browsers guess a different (e.g. executable) content type
            'X-Content-Type-Options' => 'nosniff',
            // Do not send paths/query strings (webhook tokens) to other origins
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        foreach ($defaults as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
