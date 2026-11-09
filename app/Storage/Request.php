<?php

namespace App\Storage;

use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Ramsey\Uuid\Uuid;

/**
 * A request captured by a webhook URL.
 *
 * @property string $uuid
 * @property string $token_id
 * @property string $ip
 * @property string $hostname
 * @property string $method
 * @property string|null $user_agent
 * @property string $content
 * @property array<string, mixed>|null $query
 * @property array<string, list<string|null>> $headers
 * @property string $url
 * @property array<string, mixed>|null $request
 * @property string $created_at
 * @property string $updated_at
 */
class Request extends Entity
{
    /**
     * @param string $tokenId
     * @param string|null $requestId
     * @return string
     */
    public static function getIdentifier($tokenId, $requestId = null)
    {
        if ($requestId) {
            return sprintf('token:%s:requests:%s', $tokenId, $requestId);
        }

        return sprintf('token:%s:requests', $tokenId);
    }

    /**
     * @param HttpRequest $httpRequest
     * @param string $tokenId ID of the webhook URL (token) the request was sent to
     * @return Request
     */
    public static function createFromRequest(HttpRequest $httpRequest, string $tokenId)
    {
        $request = new self([
            'uuid' => Uuid::uuid4()->toString(),
            // Never take this from $httpRequest->tokenId: Laravel resolves that magic
            // property from query/body input before route parameters.
            'token_id' => $tokenId,
            'ip' => $httpRequest->ip(),
            'hostname' => $httpRequest->getHost(),
            'method' => $httpRequest->getMethod(),
            'user_agent' => $httpRequest->header('User-Agent'),
            'content' => $httpRequest->getContent(),
            'query' => empty($httpRequest->query->all()) ? null : $httpRequest->query->all(),
            'headers' => $httpRequest->headers->all(),
            'url' => $httpRequest->fullUrl(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        if (!$httpRequest->isJson()) {
            $request->request = empty($httpRequest->request->all()) ? null : $httpRequest->request->all();
        }

        return $request;
    }

    /**
     * @return bool
     */
    public function isJson()
    {
        // Requests without a body (e.g. GET) have no Content-Type header at all.
        return ($this->headers['content-type'][0] ?? null) === 'application/json';
    }
}