<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\Processes;

#[IsReadOnly]
#[Name('processes')]
#[Title('Processes')]
#[Description('This app\'s long-running processes, told apart from other apps on the box by working directory: pid, parent, age, the release each runs from, whether that release is still current, the TCP ports it listens on, and the command (redacted). Covers the Horizon master, supervisors and workers, queue:work, the Inertia SSR wrapper and its node child, schedule:work, Octane, Reverb and anything else running from the app directory. Answers "did it restart on deploy?" and "which old process still holds the port?". Needs /proc (Linux); elsewhere it falls back to command lines mentioning the app path.')]
class ProcessesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'max:40'],
        ]);

        return Response::json(Processes::collect(kind: $validated['kind'] ?? null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->description('Only this kind: horizon-master, horizon-supervisor, horizon-worker, queue-worker, ssr, ssr-node, scheduler, scheduler-tick, octane, reverb, pulse, mcp, artisan, node, other.'),
        ];
    }
}
