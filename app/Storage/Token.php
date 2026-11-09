<?php

namespace App\Storage;

use App\Http\Requests\CreateTokenRequest;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

class Token extends Entity
{
    /**
     * @param $tokenId
     * @return string
     */
    public static function getIdentifier($tokenId = null)
    {
        return sprintf('token:%s', $tokenId);
    }

    /**
     * @param CreateTokenRequest $request
     * @return Token
     */
    public static function createFromRequest(CreateTokenRequest $request)
    {
        return new self([
            'uuid' => Uuid::uuid4()->toString(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'default_content' => $request->input('default_content', ''),
            'default_status' => (int)$request->input('default_status', 200),
            'default_content_type' => $request->input('default_content_type', 'text/plain'),
            'timeout' => (int)$request->input('timeout', null),
            'cors' => false,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}