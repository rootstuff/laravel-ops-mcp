<?php

namespace Rootstuff\OpsMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One log line per tool call on the web endpoint: who asked (IP), which
 * tool, with which arguments. Never the output, which may be the log
 * itself. The stdio server runs as a shell user and is not logged here.
 */
class LogOpsCall
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->json()->all();
        $method = (string) ($body['method'] ?? '');

        if ($method === 'tools/call') {
            try {
                Log::channel(config('ops-mcp.log_channel') ?: null)->info('ops-mcp call', [
                    'ip' => $request->ip(),
                    'tool' => $body['params']['name'] ?? null,
                    'arguments' => $body['params']['arguments'] ?? [],
                ]);
            } catch (Throwable) {
                // Logging must never block the call.
            }
        }

        return $next($request);
    }
}
