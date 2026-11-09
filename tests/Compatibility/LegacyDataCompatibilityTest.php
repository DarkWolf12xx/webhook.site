<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;

/**
 * Data written to Redis by the previous release (PHP 7.4 / Laravel 5.4) must stay
 * readable and keep working after the upgrade: same key names (no Redis prefix),
 * same JSON layout.
 */
class LegacyDataCompatibilityTest extends TestCase
{
    private const TOKEN_ID = 'c8efb5af-0bd8-4db1-8ce8-2273a4745e9e';
    private const TOKEN_WITHOUT_CORS_ID = '0d6c8a52-3b8e-4f8b-9f0e-4b1f5c2a7d10';

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/legacy-redis-records.json'), true);
        $redis = Redis::connection(config('database.redis.connection'));

        $redis->setex('token:'.self::TOKEN_ID, 3600, $this->fixture['token']);
        $redis->setex('token:'.self::TOKEN_WITHOUT_CORS_ID, 3600, $this->fixture['token_without_cors']);
        $redis->del('token:'.self::TOKEN_ID.':requests');
        foreach ($this->fixture['requests'] as $uuid => $json) {
            $redis->hset('token:'.self::TOKEN_ID.':requests', $uuid, $json);
        }
    }

    protected function tearDown(): void
    {
        $redis = Redis::connection(config('database.redis.connection'));
        $redis->del('token:'.self::TOKEN_ID, 'token:'.self::TOKEN_ID.':requests', 'token:'.self::TOKEN_WITHOUT_CORS_ID, 'token:'.self::TOKEN_WITHOUT_CORS_ID.':requests');

        parent::tearDown();
    }

    public function testLegacyTokenIsReadable()
    {
        $this->json('GET', 'token/'.self::TOKEN_ID)
            ->assertStatus(Response::HTTP_OK)
            ->assertExactJson(json_decode($this->fixture['token'], true));
    }

    public function testLegacyRequestsAreListedAndViewable()
    {
        $list = $this->json('GET', 'token/'.self::TOKEN_ID.'/requests?sorting=newest')
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('total', 2)
            ->json('data');

        $this->assertSame('GET', $list[0]['method']);
        $this->assertSame(['q' => '1'], $list[0]['query']);
        $this->assertSame('{"from":"php7.4"}', $list[1]['content']);

        $this->json('GET', 'token/'.self::TOKEN_ID.'/request/a49cd81e-e48f-4413-b666-a9c59e3af7ab')
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('headers.content-type.0', 'application/json');

        $raw = $this->get('token/'.self::TOKEN_ID.'/request/a49cd81e-e48f-4413-b666-a9c59e3af7ab/raw');
        $raw->assertStatus(Response::HTTP_OK);
        $this->assertSame('{"from":"php7.4"}', $raw->getContent());
        $this->assertStringStartsWith('application/json', $raw->headers->get('Content-Type'));

        // GET request stored with an empty Content-Type header by the old release
        $this->get('token/'.self::TOKEN_ID.'/request/b21e44b0-19ca-4c79-bd4d-c45e33c745b9/raw')
            ->assertStatus(Response::HTTP_OK);

        $this->json('GET', 'token/'.self::TOKEN_ID.'/request/latest')
            ->assertJsonPath('uuid', 'b21e44b0-19ca-4c79-bd4d-c45e33c745b9');
    }

    public function testLegacyTokenSettingsStillApplyToNewRequests()
    {
        $response = $this->post(self::TOKEN_ID);

        $response->assertStatus(Response::HTTP_ACCEPTED);
        $this->assertSame('legacy-body', $response->getContent());
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $response->assertHeader('Access-Control-Allow-Origin', '*');

        $this->json('GET', 'token/'.self::TOKEN_ID.'/requests')->assertJsonPath('total', 3);
    }

    public function testTokenCreatedBeforeCorsSettingExistedWorks()
    {
        $response = $this->post(self::TOKEN_WITHOUT_CORS_ID);
        $response->assertStatus(Response::HTTP_OK);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $this->json('PUT', 'token/'.self::TOKEN_WITHOUT_CORS_ID.'/cors/toggle')->assertExactJson(['enabled' => true]);
        $this->json('PUT', 'token/'.self::TOKEN_WITHOUT_CORS_ID.'/cors/toggle')->assertExactJson(['enabled' => false]);
    }
}
