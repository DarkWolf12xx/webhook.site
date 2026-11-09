<?php

use Illuminate\Http\Response;

class RawContentTest extends TestCase
{
    private function createTokenId(): string
    {
        return $this->json('POST', 'token')->assertStatus(Response::HTTP_CREATED)->json()['uuid'];
    }

    private function latestRequestId(string $tokenId): string
    {
        return $this->json('GET', "token/$tokenId/requests?sorting=newest")->json()['data'][0]['uuid'];
    }

    public function testRawContentOfRequestWithoutContentTypeHeader()
    {
        $this->withoutMiddleware();
        $tokenId = $this->createTokenId();

        // A GET request has no body and therefore no Content-Type header.
        $this->call('GET', $tokenId)->assertStatus(Response::HTTP_OK);
        $requestId = $this->latestRequestId($tokenId);

        $response = $this->get("token/$tokenId/request/$requestId/raw");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function testRawContentOfJsonRequest()
    {
        $this->withoutMiddleware();
        $tokenId = $this->createTokenId();

        $this->call('POST', $tokenId, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"hello":"world"}')
            ->assertStatus(Response::HTTP_OK);
        $requestId = $this->latestRequestId($tokenId);

        $response = $this->get("token/$tokenId/request/$requestId/raw");

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSame('{"hello":"world"}', $response->getContent());
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
    }
}
