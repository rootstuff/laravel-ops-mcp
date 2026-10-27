<?php

namespace Rootstuff\OpsMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A per-address, per-minute limit on the web endpoint, refused in the same
 * JSON-RPC shape as every other refusal (Laravel's own throttle middleware
 * answers with its generic exception response instead). Sits in front of
 * authentication so a token guess is limited too.
 */
class ThrottleOpsCall
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = max(1, (int) config('ops-mcp.rate_limit', 60));
        $key = 'ops-mcp:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32000, 'message' => "Rate limit of {$limit} calls a minute reached for this address. Retry in {$retryAfter} seconds."],
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - RateLimiter::attempts($key)));

        return $response;
    }
}
