<?php

use Illuminate\Http\Response;

/**
 * Regression: webhook URLs could be created without any limit. Each one is kept
 * in Redis for a week and can store up to max_requests captured requests, so a
 * single client could exhaust the server's memory.
 */
class ResourceLimitsTest extends TestCase
{
    public function testUrlCreationIsRateLimitedPerClient()
    {
        config(['app.token_create_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->json('POST', 'token')->assertStatus(Response::HTTP_CREATED);
        }

        $this->json('POST', 'token')
            ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
            ->assertHeader('Retry-After');

        // Other clients are not affected
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->json('POST', 'token')
            ->assertStatus(Response::HTTP_CREATED);
    }

    public function testRateLimitCanBeDisabled()
    {
        config(['app.token_create_rate_limit' => 0]);

        for ($i = 0; $i < 5; $i++) {
            $this->json('POST', 'token')->assertStatus(Response::HTTP_CREATED);
        }
    }

    public function testCapturingRequestsIsNotRateLimited()
    {
        config(['app.token_create_rate_limit' => 1]);
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        for ($i = 0; $i < 5; $i++) {
            $this->post($tokenId)->assertStatus(Response::HTTP_OK);
        }
    }

    public function testBodiesAbovePostMaxSizeAreRejected()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        $this->call('POST', $tokenId, [], [], [], ['CONTENT_LENGTH' => 1024 * 1024 * 1024], 'x')
            ->assertStatus(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);

        $this->assertSame(0, $this->json('GET', "token/$tokenId/requests")->json()['total']);
    }
}
