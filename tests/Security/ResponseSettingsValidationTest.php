<?php

use App\Http\Requests\CreateTokenRequest;
use App\Storage\Token;
use App\Storage\TokenStore;
use Illuminate\Http\Response;

/**
 * Regression: invalid custom response settings were accepted and then made every
 * request to the webhook URL fail with HTTP 500 (status 999, CR/LF in the
 * Content-Type header), and paths such as /{token}/2024-01-01 were mistaken for a
 * status code (HTTP 500 for the sender).
 */
class ResponseSettingsValidationTest extends TestCase
{
    public static function invalidSettings(): array
    {
        return [
            'status above 599' => [['default_status' => 999], 'default_status'],
            'status below 100' => [['default_status' => 99], 'default_status'],
            'status not numeric' => [['default_status' => 'abc'], 'default_status'],
            'header injection in content type' => [['default_content_type' => "text/plain\r\nSet-Cookie: a=b"], 'default_content_type'],
            'control character in content type' => [['default_content_type' => "text/plain\0"], 'default_content_type'],
            'content type too long' => [['default_content_type' => 'text/'.str_repeat('a', 300)], 'default_content_type'],
            'content too large' => [['default_content' => str_repeat('a', CreateTokenRequest::MAX_CONTENT_LENGTH + 1)], 'default_content'],
            'timeout too long' => [['timeout' => 11], 'timeout'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedOnCreate(array $settings, string $field)
    {
        $this->json('POST', 'token', $settings)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonStructure([$field]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedOnUpdate(array $settings, string $field)
    {
        $tokenId = $this->json('POST', 'token')->assertStatus(Response::HTTP_CREATED)->json()['uuid'];

        $this->json('PUT', "token/$tokenId", $settings)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonStructure([$field]);
    }

    public function testValidSettingsAreAccepted()
    {
        $tokenId = $this->json('POST', 'token', [
            'default_status' => 599,
            'default_content_type' => 'application/json; charset=utf-8',
            'default_content' => '{"ok":true}',
        ])->assertStatus(Response::HTTP_CREATED)->json()['uuid'];

        $response = $this->post($tokenId);

        $response->assertStatus(599);
        $this->assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('{"ok":true}', $response->getContent());
    }

    public static function pathsThatAreNotStatusCodes(): array
    {
        return [['2024-01-01'], ['order123'], ['1234'], ['20x'], ['v2/404']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pathsThatAreNotStatusCodes')]
    public function testPathsContainingDigitsAreCapturedWithDefaultStatus(string $path)
    {
        $tokenId = $this->json('POST', 'token', ['default_status' => 202])->json()['uuid'];

        $this->post("$tokenId/$path")->assertStatus(Response::HTTP_ACCEPTED);

        $this->assertSame(1, $this->json('GET', "token/$tokenId/requests")->json()['total']);
    }

    public function testStatusCodePathStillOverridesDefaultStatus()
    {
        $tokenId = $this->json('POST', 'token')->json()['uuid'];

        $this->post("$tokenId/404")->assertStatus(Response::HTTP_NOT_FOUND);
        $this->post("$tokenId/503/some/path")->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public function testUrlsStoredWithInvalidSettingsStillRespond()
    {
        // URLs created before validation existed may hold invalid values
        $tokenId = $this->json('POST', 'token')->json()['uuid'];
        $tokens = app(TokenStore::class);
        $token = $tokens->find($tokenId);
        $token->default_status = 999;
        $token->default_content_type = "text/html\r\nX-Injected: 1";
        $tokens->store($token);

        $response = $this->post($tokenId);

        $response->assertStatus(Response::HTTP_OK);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('X-Injected'));
    }
}
