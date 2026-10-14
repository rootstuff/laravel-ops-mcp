<?php

use Illuminate\Support\Facades\Log;

it('lists the read-only tools for a valid token', function () {
    $response = $this->rpc('tools/list')->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toContain('tail_log', 'list_logs', 'queue_status', 'failed_jobs', 'scheduler', 'vitals', 'config_sanity');

    foreach ($response->json('result.tools') as $tool) {
        expect($tool['annotations']['readOnlyHint'] ?? false)->toBeTrue($tool['name'].' must be read-only');
    }
});

it('refuses a missing token', function () {
    $this->rpc('tools/list', [], null)->assertStatus(401)->assertHeader('WWW-Authenticate');
});

it('refuses a wrong token', function () {
    $this->rpc('tools/list', [], 'wrong-token-0123456789abcdef0123456789abcdef')->assertStatus(401);
});

it('refuses every call when no token is configured', function () {
    config()->set('ops-mcp.token', null);

    $this->rpc('tools/list')->assertStatus(503);
});

it('refuses an address outside the allow-list', function () {
    config()->set('ops-mcp.allowed_ips', '10.0.0.0/8, 192.168.1.5');

    $this->rpc('tools/list')->assertStatus(403);
});

it('accepts an address inside the allow-list', function () {
    config()->set('ops-mcp.allowed_ips', '127.0.0.1, 10.0.0.0/8');

    $this->rpc('tools/list')->assertOk();
});

it('logs every tool call with its arguments and never its output', function () {
    Log::spy();

    $this->writeLog('laravel.log', "secret line password=abcd1234\n");
    $this->rpc('tools/call', ['name' => 'tail_log', 'arguments' => ['log' => 'single', 'lines' => 5]])->assertOk();

    Log::shouldHaveReceived('channel')->once();
});

it('registers the web route only when enabled', function () {
    expect(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'mcp/ops'))->toBeTrue();
});
