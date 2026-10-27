<?php

namespace Rootstuff\OpsMcp;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Rootstuff\OpsMcp\Console\GenerateTokenCommand;
use Rootstuff\OpsMcp\Http\Middleware\AuthenticateOpsToken;
use Rootstuff\OpsMcp\Http\Middleware\LogOpsCall;
use Rootstuff\OpsMcp\Listeners\RecordScheduledTask;
use Rootstuff\OpsMcp\Mcp\OpsServer;

class OpsMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ops-mcp.php', 'ops-mcp');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/ops-mcp.php' => config_path('ops-mcp.php')], 'ops-mcp-config');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateTokenCommand::class]);
        }

        // The stdio server: `php artisan mcp:start ops`. Whoever can run
        // artisan on the box can already read everything it reads.
        Mcp::local((string) config('ops-mcp.local_handle', 'ops'), OpsServer::class);

        if (config('ops-mcp.enabled')) {
            RateLimiter::for('ops-mcp', fn (Request $request) => Limit::perMinute(max(1, (int) config('ops-mcp.rate_limit', 60)))->by((string) $request->ip()));

            Mcp::web((string) config('ops-mcp.path', '/mcp/ops'), OpsServer::class)
                ->middleware(['throttle:ops-mcp', AuthenticateOpsToken::class, LogOpsCall::class]);
        }

        if (config('ops-mcp.scheduler.record', true)) {
            Event::listen(CommandStarting::class, [RecordScheduledTask::class, 'commandStarting']);
            Event::listen(ScheduledTaskStarting::class, [RecordScheduledTask::class, 'starting']);
            Event::listen(ScheduledTaskFinished::class, [RecordScheduledTask::class, 'finished']);
            Event::listen(ScheduledTaskFailed::class, [RecordScheduledTask::class, 'failed']);
            Event::listen(ScheduledTaskSkipped::class, [RecordScheduledTask::class, 'skipped']);
        }
    }
}
