<?php

namespace Rootstuff\OpsMcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web endpoint's lock: a single configured bearer token compared in
 * constant time, an optional IP allow-list, and a hard refusal when no
 * token is configured at all. Nothing user-scoped: the token is the
 * operator's, and every tool behind it is read-only.
 */
class AuthenticateOpsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ops-mcp.token', '');

        if (strlen($expected) < 32) {
            return $this->refuse(503, 'OPS_MCP_TOKEN is not set (or is shorter than 32 characters). Generate one with `php artisan ops-mcp:token`.');
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config('ops-mcp.allowed_ips', '')))));

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $this->refuse(403, 'This address is not on the allow-list.');
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return $this->refuse(401, 'A valid bearer token is required.')->withHeaders(['WWW-Authenticate' => 'Bearer realm="ops-mcp"']);
        }

        return $next($request);
    }

    private function refuse(int $status, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $status === 401 ? -32001 : -32000, 'message' => $message],
        ], $status);
    }
}
