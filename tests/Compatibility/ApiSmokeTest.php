<?php

use Illuminate\Http\Response;

/**
 * End-to-end walk through every public endpoint, as used by the web UI and by
 * API clients, after the PHP 8.5 / Laravel 13 upgrade.
 */
class ApiSmokeTest extends TestCase
{
    public function testFullUrlLifecycle()
    {
        $this->get('/')->assertStatus(Response::HTTP_OK)->assertSee('AppConfig', false);

        // Create a URL with a custom response
        $token = $this->json('POST', 'token', [
            'default_status' => 201,
            'default_content' => 'created',
            'default_content_type' => 'text/plain',
        ])->assertStatus(Response::HTTP_CREATED)->json();
        $id = $token['uuid'];

        // Capture requests of every method, with query strings, form values and JSON
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->call($method, "$id/path/$method?q=$method", ['field' => $method]);
            $response->assertStatus(Response::HTTP_CREATED);
            $this->assertSame('created', $response->getContent());
            $response->assertHeader('X-Token-Id', $id);
        }
        $this->call('POST', $id, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":[1,2]}')
            ->assertStatus(Response::HTTP_CREATED);

        $list = $this->json('GET', "token/$id/requests?per_page=100")
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('total', 7)
            ->json('data');
        $byMethod = collect($list)->keyBy('method');
        $this->assertSame(['q' => 'PATCH'], $byMethod['PATCH']['query']);
        $this->assertSame(['field' => 'PUT'], $byMethod['PUT']['request']);
        $this->assertStringEndsWith("/$id/path/DELETE?q=DELETE", $byMethod['DELETE']['url']);

        // Single request, raw body, delete one
        $requestId = $list[0]['uuid'];
        $this->json('GET', "token/$id/request/$requestId")->assertJsonPath('uuid', $requestId);
        $this->get("token/$id/request/$requestId/raw")->assertStatus(Response::HTTP_OK);
        $this->json('DELETE', "token/$id/request/$requestId")->assertExactJson(['status' => true]);
        $this->json('GET', "token/$id/request/$requestId")->assertStatus(Response::HTTP_NOT_FOUND);

        // Update the URL settings, toggle CORS
        $this->json('PUT', "token/$id", ['default_status' => 200, 'default_content' => 'updated'])
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('default_content', 'updated');
        $this->post($id)->assertStatus(Response::HTTP_OK)->assertSee('updated');
        $this->json('PUT', "token/$id/cors/toggle")->assertExactJson(['enabled' => true]);

        // Delete all requests, then the URL itself
        $this->json('DELETE', "token/$id/request")->assertExactJson(['status' => true]);
        $this->json('GET', "token/$id/requests")->assertJsonPath('total', 0);
        $this->json('DELETE', "token/$id")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->json('GET', "token/$id")->assertStatus(Response::HTTP_GONE);
        $this->post($id)->assertStatus(Response::HTTP_GONE);
    }

    public function testQuotaReturnsGone()
    {
        config(['app.max_requests' => 3]);
        $id = $this->json('POST', 'token')->json()['uuid'];

        for ($i = 0; $i < 3; $i++) {
            $this->post($id)->assertStatus(Response::HTTP_OK);
        }

        $this->post($id)->assertStatus(Response::HTTP_GONE);
        $this->json('GET', "token/$id/requests")->assertJsonPath('total', 3);
    }

    public function testUnknownUrlIsGone()
    {
        $this->post('00000000-0000-4000-8000-000000000000')->assertStatus(Response::HTTP_GONE);
        $this->json('GET', 'token/00000000-0000-4000-8000-000000000000')
            ->assertStatus(Response::HTTP_GONE)
            ->assertJsonPath('error.message', 'Token not found');
    }
}
