<?php

use Illuminate\Http\Response;

/**
 * Regression: the web UI was sent without any security headers, so other sites
 * could frame it (clickjacking of "Delete all requests", "Edit URL", ...) and
 * there was no Content-Security-Policy limiting the impact of an XSS.
 */
class WebUiSecurityHeadersTest extends TestCase
{
    public function testWebUiCannotBeFramedAndHasCsp()
    {
        $response = $this->get('/');

        $response->assertStatus(Response::HTTP_OK);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-(inline|eval)'/", $csp);
    }

    public function testInlineScriptIsAllowedOnlyThroughAPerResponseNonce()
    {
        $first = $this->get('/');
        $second = $this->get('/');

        preg_match("/'nonce-([^']+)'/", $first->headers->get('Content-Security-Policy'), $nonce);
        $this->assertNotEmpty($nonce[1] ?? null);
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', $first->getContent());

        // A fresh nonce for every response
        $this->assertStringNotContainsString($nonce[1], $second->headers->get('Content-Security-Policy'));

        // AngularJS must not need eval() under this policy
        $this->assertStringContainsString('ng-csp="no-unsafe-eval"', $first->getContent());
    }

    public function testConfigurationValuesAreJsonEncodedInTheInlineScript()
    {
        $value = '"</script><script>alert(1)</script>';
        config(['broadcasting.connections.pusher.key' => $value]);

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $content);
        $encoded = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringContainsString('PusherToken: '.$encoded.',', $content);
    }

    public function testApiAndWebhookResponsesGetBaselineHeaders()
    {
        $create = $this->json('POST', 'token');
        $create->assertHeader('X-Content-Type-Options', 'nosniff');
        $create->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $webhook = $this->post($create->json()['uuid']);
        $webhook->assertHeader('X-Content-Type-Options', 'nosniff');
        $webhook->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        // The webhook response keeps its own sandbox policy, not the UI policy
        $webhook->assertHeader('Content-Security-Policy', 'sandbox');
    }
}
