<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\QueueStatusTool;
use Rootstuff\OpsMcp\Mcp\Tools\VitalsTool;
use Rootstuff\OpsMcp\Support\Vitals;

it('reports versions, environment and host figures', function () {
    OpsServer::tool(VitalsTool::class)->assertOk()
        ->assertSee('"laravel":"'.app()->version().'"')
        ->assertSee('"php":"'.PHP_VERSION.'"')
        ->assertSee('"env":"testing"')
        ->assertSee('"hostname"')
        ->assertSee('"notes":');
});

it('counts processes with a plain pgrep and explains what the host cannot answer', function () {
    $vitals = Vitals::collect();

    // pgrep prints one pid a line on every platform; the Linux-only -c is not used.
    expect($vitals['processes']['php-fpm'])->toBeInt();
    expect($vitals['processes']['nginx'])->toBeInt();
    expect($vitals['host']['cpus'])->toBeGreaterThan(0);
    expect($vitals['host']['uptime'])->toBeString();

    if (PHP_OS_FAMILY === 'Linux') {
        expect($vitals['host']['memory'])->toHaveKeys(['total_mb', 'available_mb', 'swap_used_mb']);
        expect($vitals['host']['notes'])->toBe([]);
    } else {
        expect($vitals['host']['memory'])->toBeNull();
        expect($vitals['host']['notes'])->toHaveCount(1);
        expect($vitals['host']['notes'][0])->toContain('/proc/meminfo')->toContain(PHP_OS_FAMILY);
    }
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
