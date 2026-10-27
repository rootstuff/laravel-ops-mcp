<?php

use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\ConfigSanityTool;
use Rootstuff\OpsMcp\Support\ConfigSanity;

it('passes a sane testing setup with the scheduler flagged as not ticking', function () {
    $response = OpsServer::tool(ConfigSanityTool::class)->assertOk();

    $response->assertSee('"fail":0')->assertSee('"check":"reachable.database"')->assertSee('"check":"scheduler"')->assertSee('no recent tick');
});

it('fails debug mode in production and warns on local drivers and missing caches', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', true);

    $response = OpsServer::tool(ConfigSanityTool::class)->assertOk();

    $response->assertSee('Debug mode is on in production')->assertSee('The sync driver is for local work')->assertSee('config:cache');
});

it('fails a missing app key', function () {
    config()->set('app.key', '');

    OpsServer::tool(ConfigSanityTool::class)->assertOk()->assertSee('No APP_KEY');
});

it('warns when long-running processes still run from a release older than current', function () {
    ['proc' => $proc, 'root' => $root] = fakeProc($this->logDir);

    $check = collect(ConfigSanity::collect($proc, $root)['checks'])->firstWhere('check', 'processes');

    expect($check['status'])->toBe('warn')
        ->and($check['value'])->toBe('2 of 5 from old releases')
        ->and($check['note'])->toContain('still run from release(s) old while current is new')->toContain('ssr');
});

it('reports processes as not scoped without /proc', function () {
    $check = collect(ConfigSanity::collect($this->logDir.'/no-proc', $this->logDir)['checks'])->firstWhere('check', 'processes');

    expect($check['status'])->toBe('ok')->and($check['value'])->toBe('not scoped');
});
