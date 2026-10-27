<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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

it('records a tick when schedule:run starts even with nothing due or no tasks at all', function () {
    // The framework bridges Symfony's console events to CommandStarting only
    // outside unit tests; turn the bridge on so the real command path is tested.
    app(Kernel::class)->rerouteSymfonyCommandEvents();
    expect(app(Schedule::class)->events())->toBe([]);

    OpsServer::tool(SchedulerTool::class)->assertOk()->assertSee('"running":false');

    $this->artisan('schedule:run')->assertSuccessful();

    OpsServer::tool(SchedulerTool::class)->assertOk()->assertSee('"running":true')->assertSee('"tasks":[]');
});

it('ignores other console commands when stamping the tick', function () {
    app(Kernel::class)->rerouteSymfonyCommandEvents();

    $this->artisan('env')->assertSuccessful();

    OpsServer::tool(SchedulerTool::class)->assertOk()->assertSee('"running":false');
});
