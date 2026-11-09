<?php

namespace App\Http\Middleware;

use App\Events\IpThrottled;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests as LaravelThrottleRequests;

class ThrottleRequests extends LaravelThrottleRequests
{
    /**
     * Same as the framework's rate limiter, but fires an IpThrottled event when a
     * client exceeds the limit (see App\Listeners\BlockIp).
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @param int|string $maxAttempts
     * @param float|int $decayMinutes
     * @param string $prefix
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $key = $prefix.$this->resolveRequestSignature($request);
        $maxAttempts = $this->resolveMaxAttempts($request, $maxAttempts);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            broadcast(new IpThrottled($request->ip()));

            throw $this->buildException($request, $key, $maxAttempts);
        }

        $this->limiter->hit($key, (int) (60 * $decayMinutes));

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts)
        );
    }
}
