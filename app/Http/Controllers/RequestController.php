<?php

namespace App\Http\Controllers;

use App\Events\RequestCreated;
use App\Storage\Request;
use App\Storage\RequestStore;
use App\Storage\Token;
use App\Storage\TokenStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response;

class RequestController extends Controller
{
    /**
     * @var Repository
     */
    private $cache;
    /**
     * @var TokenStore
     */
    private $tokens;
    /**
     * @var RequestStore
     */
    private $requests;

    /**
     * RequestController constructor.
     * @param Repository $cache
     * @param TokenStore $tokens
     * @param RequestStore $requests
     */
    public function __construct(Repository $cache, TokenStore $tokens, RequestStore $requests)
    {
        $this->cache = $cache;
        $this->tokens = $tokens;
        $this->requests = $requests;
    }

    /**
     * @param HttpRequest $httpRequest
     * @return Response
     */
    public function create(HttpRequest $httpRequest, $tokenId)
    {
        $token = $this->tokens->find($tokenId);

        $this->guardOverQuota($token);

        if ($token->timeout) {
            sleep($token->timeout);
        }

        $request = Request::createFromRequest($httpRequest, $token->uuid);

        $this->requests->store($token, $request);

        broadcast(new RequestCreated($token, $request));

        // /{token}/{status} overrides the URL's default status. Only an exact
        // three-digit segment counts: paths such as /{token}/2024-01-01 used to be
        // taken as a status code and made the response fail with HTTP 500.
        $responseStatus = preg_match('/\A[1-5][0-9]{2}\z/', (string) $httpRequest->segment(2))
            ? (int) $httpRequest->segment(2)
            : self::validStatus($token->default_status);

        $response = new Response(
            $token->default_content,
            $responseStatus,
            [
                'Content-Type' => self::validContentType($token->default_content_type),
                'X-Request-Id' => $request->uuid,
                'X-Token-Id' => $token->uuid,
            ]
        );

        $response->headers->add(static::untrustedContentHeaders());

        if ($token->cors) {
            $response->withHeaders($this::corsHeaders());
        }

        return $response;
    }

    /**
     * Settings are validated when a URL is created or updated; this also covers
     * URLs stored before that validation existed.
     *
     * @param mixed $status
     * @return int
     */
    private static function validStatus($status): int
    {
        $status = (int) $status;

        return $status >= 100 && $status <= 599 ? $status : Response::HTTP_OK;
    }

    /**
     * @param mixed $contentType
     * @return string
     */
    private static function validContentType($contentType): string
    {
        return is_string($contentType) && preg_match('/\A[\x20-\x7E]{1,255}\z/', $contentType)
            ? $contentType
            : 'text/plain';
    }

    /**
     * @param Token $token
     * @return void
     */
    private function guardOverQuota($token)
    {
        if ($this->tokens->countRequests($token) >= config('app.max_requests')) {
            abort(Response::HTTP_GONE, 'Too many requests, please create a new URL/token');
        }
    }

    /**
     * @param HttpRequest $httpRequest
     * @param string $tokenId
     * @return JsonResponse
     */
    public function all(HttpRequest $httpRequest, $tokenId)
    {
        $token = $this->tokens->find($tokenId);
        $sorting = $httpRequest->input('sorting', 'oldest');
        $page = (int)$httpRequest->input('page', 1);
        $perPage = (int)$httpRequest->input('per_page', 50);
        $requests = $this->requests->all($token, $page, $perPage, $sorting);
        $total = $this->tokens->countRequests($token);

        return new JsonResponse([
            'data' => $requests,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'is_last_page' => ($requests->count() + (($page - 1) * $perPage)) >= $total,
            'from' => (($page - 1) * $perPage) + 1,
            'to' => min($total, $requests->count() + (($page - 1) * $perPage)),
        ]);
    }

    /**
     * @param $tokenId
     * @param $requestId
     * @return mixed
     */
    public function find($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        return new JsonResponse($request);
    }

    /**
     * @param string $tokenId
     * @param string $requestId
     * @return Response
     */
    public function raw($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        $contentType = $request->isJson() ? 'application/json' : 'text/plain';

        return new Response(
            $request->content,
            Response::HTTP_OK,
            ['content-type' => $contentType] + static::untrustedContentHeaders()
        );
    }

    /**
     * @param string $tokenId
     * @param string $requestId
     * @return JsonResponse
     */
    public function delete($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        return new JsonResponse([
            'status' => (bool)$this->requests->delete($token, $request)
        ]);
    }

    /**
     * @param $tokenId
     * @return JsonResponse
     */
    public function deleteByToken($tokenId)
    {
        $token = $this->tokens->find($tokenId);

        return new JsonResponse([
            'status' => (bool)$this->requests->deleteByToken($token)
        ]);
    }

    /**
     * Get the latest request associated with a token.
     *
     * @param HttpRequest $httpRequest
     * @param string $tokenId
     * @return JsonResponse
     *
     * @throws NotFoundHttpException
     */
    public function latest(HttpRequest $httpRequest, string $tokenId): JsonResponse
    {
        $token = $this->tokens->find($tokenId);

        $requests = $this->requests->all(
            $token,
            1,
            1,
            'newest'
        );

        if (empty($requests)) {
            throw new NotFoundHttpException('Request not found');
        }

        return new JsonResponse($requests[0]);
    }
}
