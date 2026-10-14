<?php

namespace Rootstuff\OpsMcp\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rootstuff\OpsMcp\OpsMcpServiceProvider;

abstract class TestCase extends Orchestra
{
    public const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    protected string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists($this->logDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, OpsMcpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->logDir = sys_get_temp_dir().'/ops-mcp-tests-'.getmypid();

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.env', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $this->logDir.'/laravel.log']);
        $app['config']->set('logging.channels.daily', ['driver' => 'daily', 'path' => $this->logDir.'/daily.log', 'days' => 14]);
        $app['config']->set('ops-mcp.enabled', true);
        $app['config']->set('ops-mcp.token', self::TOKEN);
        $app['config']->set('ops-mcp.scheduler.record', true);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = [], ?string $token = self::TOKEN): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/mcp/ops', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
    }

    protected function writeLog(string $name, string $contents): string
    {
        $path = $this->logDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
