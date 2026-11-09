<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class IndexController extends Controller
{
    /**
     * The single-page request viewer.
     *
     * It is served with a Content-Security-Policy (inline script allowed only with a
     * per-response nonce) and must not be framed by other sites (clickjacking of
     * e.g. "Delete all requests").
     *
     * @return Response
     */
    public function __invoke()
    {
        $nonce = base64_encode(random_bytes(18));

        return response()
            ->view('app', ['cspNonce' => $nonce])
            ->withHeaders([
                'Content-Security-Policy' => str_replace('{nonce}', $nonce, (string) config('app.ui_csp')),
                'X-Frame-Options' => 'DENY',
            ]);
    }
}
