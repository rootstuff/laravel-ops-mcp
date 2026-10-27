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
use Rootstuff\OpsMcp\Support\LogFiles;

#[IsReadOnly]
#[Name('tail_log')]
#[Title('Tail log')]
#[Description('The last lines of one log, redacted. Leave `log` out for the app\'s default channel, or name a channel (single and daily channels, plus `{channel}-yesterday` for daily ones), a file from storage/logs as list_logs shows them, or an allow-listed extra. A plain tail reads the last 256 KB of the file; with `grep` (case-insensitive) or `since` (a window like 30m, 2h, 1d, or an ISO time), both applied before the line limit, it streams the last 16 MB. Warns when another named log was written more recently than the one read. Secrets are redacted before anything leaves.')]
class TailLogTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'log' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('ops-mcp.logs.max_lines', 200))],
            'grep' => ['nullable', 'string', 'max:200'],
            'since' => ['nullable', 'string', 'max:40'],
        ]);

        return Response::json(LogFiles::tail($validated['log'] ?? null, (int) ($validated['lines'] ?? 50), $validated['grep'] ?? null, $validated['since'] ?? null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'log' => $schema->string()->description('Which log: a channel name ('.implode(', ', array_keys(LogFiles::named())).') or a file name from storage/logs, e.g. laravel-2026-10-14.log. Omit for the default channel'.(($default = LogFiles::defaultLog()) !== null ? " ({$default})" : '').'.'),
            'lines' => $schema->integer()->description('Last N lines (default 50, max '.(int) config('ops-mcp.logs.max_lines', 200).').'),
            'grep' => $schema->string()->description('Only lines containing this text, case-insensitive.'),
            'since' => $schema->string()->description('Only entries from this point on: a window back from now like 30m, 2h or 1d, or an ISO-8601 time. Keyed off each entry\'s leading timestamp; stack-trace lines follow their entry.'),
        ];
    }
}
