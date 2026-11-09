<?php

use App\Events\RequestCreated;
use Illuminate\Support\Facades\Event;

/**
 * Regression: a captured request's token_id was read from $request->tokenId,
 * which Laravel resolves from query/body input before the route parameter. A
 * sender could add tokenId=<other URL> and have the request broadcast to that
 * other URL's live view, bypassing its quota and settings.
 */
class RequestTokenBindingTest extends TestCase
{
    public function testTokenIdInputCannotRedirectCapturedRequest()
    {
        $ownTokenId = $this->json('POST', 'token')->json()['uuid'];
        $otherTokenId = $this->json('POST', 'token')->json()['uuid'];

        Event::fake([RequestCreated::class]);

        $this->post("$ownTokenId?tokenId=$otherTokenId", ['tokenId' => $otherTokenId])->assertOk();
        $this->postJson($ownTokenId, ['tokenId' => $otherTokenId])->assertOk();

        // Stored under the URL it was sent to, with the matching token_id ...
        $captured = $this->json('GET', "token/$ownTokenId/requests")->json();
        $this->assertSame(2, $captured['total']);
        foreach ($captured['data'] as $request) {
            $this->assertSame($ownTokenId, $request['token_id']);
        }
        $this->assertSame(0, $this->json('GET', "token/$otherTokenId/requests")->json()['total']);

        // ... and only broadcast on that URL's channel.
        Event::assertDispatchedTimes(RequestCreated::class, 2);
        Event::assertDispatched(RequestCreated::class, function (RequestCreated $event) use ($ownTokenId) {
            return $event->broadcastOn()->name === $ownTokenId;
        });
        Event::assertNotDispatched(RequestCreated::class, function (RequestCreated $event) use ($otherTokenId) {
            return $event->broadcastOn()->name === $otherTokenId;
        });
    }
}
