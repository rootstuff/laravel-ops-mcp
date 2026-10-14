<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\ConfigSanity;

#[IsReadOnly]
#[Name('config_sanity')]
#[Title('Config sanity')]
#[Description('A checklist of the configuration mistakes behind most production surprises, each with ok, warn or fail: debug on in production, missing APP_KEY, config and route caches not built, local-only drivers (array, sync) in production, database, cache and Redis reachability, writable storage and bootstrap/cache directories, and whether the scheduler is ticking. Driver names and verdicts only, never values.')]
class ConfigSanityTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(ConfigSanity::collect());
    }
}
