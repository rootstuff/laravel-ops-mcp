<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\FailedJobsTool;

beforeEach(function () {
    config()->set('queue.failed', ['database' => 'testing', 'table' => 'failed_jobs']);

    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
});

it('groups failures by queue, job and normalised error', function () {
    foreach ([1, 2, 3] as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => "u{$i}", 'connection' => 'redis', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendMail']),
            'exception' => "GuzzleHttp\\Exception\\ConnectException: cURL error 28 after {$i}00 ms (see https://x?sig={$i}) password=hunter{$i}\n#0 /releases/abc1234/app/x.php",
            'failed_at' => now()->subMinutes($i),
        ]);
    }
    DB::table('failed_jobs')->insert([
        'uuid' => 'old', 'connection' => 'redis', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Old']),
        'exception' => "RuntimeException: ancient\n",
        'failed_at' => now()->subDays(3),
    ]);

    $response = OpsServer::tool(FailedJobsTool::class, ['hours' => 24])->assertOk();

    $response->assertSee('"total_failures":3')
        ->assertSee('App\\\\Jobs\\\\SendMail')
        ->assertSee('after N ms')
        ->assertSee('password=[redacted]')
        ->assertDontSee('hunter1')
        ->assertDontSee('ancient')
        ->assertSee('"failures":3');
});

it('reports an unreachable table as an error, not an exception', function () {
    config()->set('queue.failed.table', 'missing_table');

    OpsServer::tool(FailedJobsTool::class)->assertOk()->assertSee('"error"');
});
