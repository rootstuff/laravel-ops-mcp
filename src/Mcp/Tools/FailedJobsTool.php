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
use Rootstuff\OpsMcp\Support\FailedJobs;

#[IsReadOnly]
#[Name('failed_jobs')]
#[Title('Failed jobs')]
#[Description('Failed jobs over a window, grouped by queue, job class and the normalised first line of the exception, most frequent first, plus the ten most recent with their ids for `queue:retry`.')]
class FailedJobsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return Response::json(FailedJobs::collect((int) ($validated['hours'] ?? 24), (int) ($validated['limit'] ?? 20)));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->description('Window in hours (default 24, max 720).'),
            'limit' => $schema->integer()->description('Max groups (default 20, max 100).'),
        ];
    }
}
