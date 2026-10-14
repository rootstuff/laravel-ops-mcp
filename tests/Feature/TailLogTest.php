<?php

use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\ListLogsTool;
use Rootstuff\OpsMcp\Mcp\Tools\TailLogTool;

it('tails a channel by name and redacts secrets', function () {
    $this->writeLog('laravel.log', "[2026-10-14] local.INFO: one\n[2026-10-14] local.ERROR: boom token=abcdefgh\n[2026-10-14] local.INFO: three\n");

    $response = OpsServer::tool(TailLogTool::class, ['log' => 'single', 'lines' => 2]);

    $response->assertOk()
        ->assertSee('boom token=[redacted]')
        ->assertSee('three')
        ->assertDontSee('abcdefgh')
        ->assertDontSee('local.INFO: one');
});

it('resolves a daily channel to today and yesterday', function () {
    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    $this->writeLog("daily-{$today}.log", "today's line\n");
    $this->writeLog("daily-{$yesterday}.log", "yesterday's line\n");

    OpsServer::tool(TailLogTool::class, ['log' => 'daily'])->assertOk()->assertSee("today's line");
    OpsServer::tool(TailLogTool::class, ['log' => 'daily-yesterday'])->assertOk()->assertSee("yesterday's line");
});

it('greps across the window and keeps the last matches', function () {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = $i % 2 === 0 ? "line {$i} ERROR" : "line {$i} info";
    }
    $this->writeLog('laravel.log', implode("\n", $lines)."\n");

    $response = OpsServer::tool(TailLogTool::class, ['log' => 'single', 'lines' => 3, 'grep' => 'error']);

    $response->assertOk()->assertSee('line 300 ERROR')->assertSee('line 296 ERROR')->assertDontSee('line 294 ERROR')->assertDontSee('info');
    $response->assertSee('"matched":150');
});

it('reads a bare file name from storage/logs only', function () {
    config()->set('ops-mcp.logs.extra', []);
    $this->app->useStoragePath($this->logDir);
    mkdir($this->logDir.'/logs');
    $this->writeLog('logs/worker.log', "from the worker\n");

    OpsServer::tool(TailLogTool::class, ['log' => 'worker.log'])->assertOk()->assertSee('from the worker');
});

it('refuses paths, traversal and unknown names', function (string $log) {
    OpsServer::tool(TailLogTool::class, ['log' => $log])->assertOk()->assertSee('Unknown log');
})->with(['../../.env', '/etc/passwd', 'storage/logs/laravel.log', '..log', 'nope']);

it('reads an allow-listed extra by its name only', function () {
    $path = $this->writeLog('nginx-error.log', "upstream timed out\n");
    config()->set('ops-mcp.logs.extra', ['nginx-error' => $path]);

    OpsServer::tool(TailLogTool::class, ['log' => 'nginx-error'])->assertOk()->assertSee('upstream timed out');
    OpsServer::tool(TailLogTool::class, ['log' => 'nginx-error.log'])->assertOk()->assertSee('does not exist');
});

it('lists the named channels and the files in storage/logs', function () {
    $this->app->useStoragePath($this->logDir);
    mkdir($this->logDir.'/logs');
    $this->writeLog('logs/laravel.log', "x\n");

    OpsServer::tool(ListLogsTool::class)->assertOk()->assertSee('"single"')->assertSee('"daily-yesterday"')->assertSee('laravel.log');
});
