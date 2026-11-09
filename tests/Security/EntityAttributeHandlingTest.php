<?php

use App\Events\RequestCreated;
use App\Storage\Request;
use App\Storage\Token;
use App\Storage\TokenStore;
use Illuminate\Http\Response;

/**
 * Regression: App\Storage\Entity had no __isset()/__unset(), so
 * - isset($token->cors) was always false and "toggle CORS" could only ever
 *   turn CORS on (Access-Control-Allow-Origin: * could not be revoked);
 * - RequestCreated's unset() of oversized content/headers did nothing, so the
 *   full payload was still broadcast to every subscriber.
 */
class EntityAttributeHandlingTest extends TestCase
{
    public function testCorsCanBeDisabledAgain()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        $this->json('PUT', "token/$tokenId/cors/toggle")->assertExactJson(['enabled' => true]);
        $this->post($tokenId)->assertHeader('Access-Control-Allow-Origin', '*');

        $this->json('PUT', "token/$tokenId/cors/toggle")->assertExactJson(['enabled' => false]);
        $this->assertFalse($this->post($tokenId)->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($this->json('GET', "token/$tokenId")->json()['cors']);
    }

    public function testUrlsWithoutCorsAttributeStillWork()
    {
        // URLs stored before the CORS setting existed have no "cors" attribute
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        $tokens = app(TokenStore::class);
        $attributes = $tokens->find($tokenId)->attributes();
        unset($attributes['cors']);
        $tokens->store(new Token($attributes));

        $response = $this->post($tokenId);
        $response->assertStatus(Response::HTTP_OK);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $this->json('PUT', "token/$tokenId/cors/toggle")->assertExactJson(['enabled' => true]);
    }

    public function testOversizedBroadcastPayloadIsTruncated()
    {
        $token = new Token(['uuid' => 'a2f1b8a4-0c3e-4d55-9b7e-0b1c2d3e4f50']);
        $request = new Request([
            'uuid' => '5e8e5a8e-2b0e-4b55-8a2a-6d2a0f2b9c11',
            'token_id' => $token->uuid,
            'content' => str_repeat('x', 1_100_000),
            'headers' => ['x-big' => ['y']],
            'user_agent' => 'test',
            'method' => 'POST',
        ]);

        $event = new RequestCreated($token, $request);

        $this->assertTrue($event->truncated);
        $payload = $event->request->toArray();
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('headers', $payload);
        $this->assertArrayNotHasKey('user_agent', $payload);
        $this->assertSame('POST', $payload['method']);
        $this->assertLessThan(1000, strlen($event->request->toJson()));
    }
}
