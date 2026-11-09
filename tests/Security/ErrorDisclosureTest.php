<?php

use App\Storage\RequestStore;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Regression: error responses exposed internals. Debug mode was on by default
 * (exception class, message, file, line and full stack trace in every JSON error),
 * GET token/{id}/request/latest crashed with HTTP 500 for a URL without requests,
 * and the webhook token (a bearer credential) was written to the log.
 */
class ErrorDisclosureTest extends TestCase
{
    public function testDebugModeIsOffUnlessEnabled()
    {
        if (getenv('APP_DEBUG') !== false || isset($_ENV['APP_DEBUG']) || isset($_SERVER['APP_DEBUG'])) {
            $this->markTestSkipped('APP_DEBUG is set in this environment.');
        }

        $this->assertFalse(config('app.debug'));
    }

    public function testInternalErrorsDoNotRevealDetails()
    {
        config(['app.debug' => false]);
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        $this->mock(RequestStore::class, function ($mock) {
            $mock->shouldReceive('all')->andThrow(new RuntimeException('Cannot connect to /run/secret/redis.sock'));
        });

        $response = $this->json('GET', "token/$tokenId/requests");

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'An internal error occurred');

        $error = $response->json('error');
        foreach (['exception', 'trace', 'file', 'line'] as $key) {
            $this->assertArrayNotHasKey($key, $error);
        }
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString(base_path(), $response->getContent());
    }

    public function testLatestRequestOfEmptyUrlIsNotFound()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        $this->json('GET', "token/$tokenId/request/latest")
            ->assertStatus(Response::HTTP_NOT_FOUND)
            ->assertJsonPath('error.message', 'Request not found');
    }

    public function testLatestRequestIsReturned()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        $this->call('POST', $tokenId, [], [], [], [], 'first');
        $this->travel(2)->seconds();
        $this->call('POST', $tokenId, [], [], [], [], 'second');

        $this->json('GET', "token/$tokenId/request/latest")
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('content', 'second')
            ->assertJsonPath('token_id', $tokenId);
    }

    public function testWebhookTokenIsNotLogged()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        Log::spy();

        $this->json('PUT', "token/$tokenId/cors/toggle")->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($tokenId) {
            return !str_contains($message.json_encode($context), $tokenId);
        });
        Log::shouldNotHaveReceived('info', function ($message, $context = []) use ($tokenId) {
            return str_contains($message.json_encode($context), $tokenId);
        });
    }
}
