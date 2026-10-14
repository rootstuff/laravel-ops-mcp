<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\SchedulerState;

#[IsReadOnly]
#[Name('scheduler')]
#[Title('Scheduler')]
#[Description('Every scheduled task with its cron expression, next due time and last run (started, finished, runtime, exit code or error), and whether the scheduler itself is ticking: the last `schedule:run` seen and its age. No recent tick means the cron entry is missing or stopped.')]
class SchedulerTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(SchedulerState::collect());
    }
}
