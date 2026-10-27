<?php

namespace Rootstuff\OpsMcp\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Rootstuff\OpsMcp\Mcp\Tools\ConfigSanityTool;
use Rootstuff\OpsMcp\Mcp\Tools\FailedJobsTool;
use Rootstuff\OpsMcp\Mcp\Tools\ListLogsTool;
use Rootstuff\OpsMcp\Mcp\Tools\QueueStatusTool;
use Rootstuff\OpsMcp\Mcp\Tools\SchedulerTool;
use Rootstuff\OpsMcp\Mcp\Tools\TailLogTool;
use Rootstuff\OpsMcp\Mcp\Tools\VitalsTool;

/**
 * Read-only operations tools for one Laravel application. Everything here
 * reads through the app itself: it answers what the logs say, what the
 * queues hold, what failed, what the scheduler did and how the box feels.
 * It cannot answer for an app that is down.
 */
#[Name('Laravel Ops')]
#[Version('0.2.0')]
#[Instructions('Read-only operations tools for this Laravel application, meant to replace "ssh in and look". Start with config_sanity for a quick verdict on the setup, vitals for the box and the running release, then tail_log (with grep) for what the app logged, failed_jobs for what the queue dropped, queue_status for backlog and workers (and Horizon supervisors, with starved queues), and scheduler for whether cron is ticking and when each task last ran. Every tool reads through the application itself and nothing takes a path or a command: if the app is down, this server is down with it. Output is redacted; still treat it as production data.')]
class OpsServer extends Server
{
    protected array $tools = [
        ConfigSanityTool::class,
        VitalsTool::class,
        TailLogTool::class,
        ListLogsTool::class,
        FailedJobsTool::class,
        QueueStatusTool::class,
        SchedulerTool::class,
    ];
}
