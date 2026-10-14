<?php

use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\ConfigSanityTool;

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
