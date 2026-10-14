<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\SchedulerTool;

it('lists tasks with next due time and reports no tick until the scheduler runs', function () {
    app(Schedule::class)->command('inspire')->hourly();

    $response = OpsServer::tool(SchedulerTool::class)->assertOk();

    $response->assertSee('artisan inspire')->assertSee('"expression":"0 * * * *"')->assertSee('"running":false')->assertSee('"last_run":null');
});

it('records the last tick and each task run from the scheduler events', function () {
    $event = app(Schedule::class)->command('inspire')->hourly();
    $event->exitCode = 0;

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 1.234));

    $response = OpsServer::tool(SchedulerTool::class)->assertOk();

    $response->assertSee('"running":true')->assertSee('"status":"ok"')->assertSee('"runtime_seconds":1.23');
});
