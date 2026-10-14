<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\QueueStatusTool;
use Rootstuff\OpsMcp\Mcp\Tools\VitalsTool;

it('reports versions, environment and host figures', function () {
    OpsServer::tool(VitalsTool::class)->assertOk()
        ->assertSee('"laravel":"'.app()->version().'"')
        ->assertSee('"php":"'.PHP_VERSION.'"')
        ->assertSee('"env":"testing"')
        ->assertSee('"hostname"');
});

it('reports a sync queue as having nothing to count and no Horizon', function () {
    OpsServer::tool(QueueStatusTool::class)->assertOk()->assertSee('"driver":"sync"')->assertSee('"horizon":null');
});

it('counts pending jobs on a database queue', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('ops-mcp.queues.names', ['default', 'mail']);

    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Queue::connection('database')->pushRaw('{}', 'mail');
    Queue::connection('database')->pushRaw('{}', 'mail');

    $response = OpsServer::tool(QueueStatusTool::class)->assertOk();

    $response->assertSee('"driver":"database"')->assertSee('"mail":{')->assertSee('"pending":2');
});
