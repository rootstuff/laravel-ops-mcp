<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\LogFiles;

#[IsReadOnly]
#[Name('list_logs')]
#[Title('List logs')]
#[Description('The logs tail_log can read: the named channels with the file each resolves to right now, and every file in storage/logs with its size and last write, newest first.')]
class ListLogsTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json([
            'named' => LogFiles::named(),
            'files' => LogFiles::files(),
        ]);
    }
}
