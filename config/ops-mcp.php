<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web endpoint
    |--------------------------------------------------------------------------
    |
    | The HTTP server is off until you turn it on and set a token. The local
    | (stdio) server, started with `php artisan mcp:start ops`, needs neither:
    | whoever can run artisan on the box can already read everything it reads.
    |
    */

    'enabled' => (bool) env('OPS_MCP_ENABLED', false),

    'path' => env('OPS_MCP_PATH', '/mcp/ops'),

    /*
    | A long random string. Generate one with `php artisan ops-mcp:token`.
    | Requests carry it as `Authorization: Bearer <token>`.
    */
    'token' => env('OPS_MCP_TOKEN'),

    /*
    | Comma-separated IPs or CIDR ranges allowed to call the web endpoint.
    | Empty allows any address (the token still applies).
    */
    'allowed_ips' => env('OPS_MCP_ALLOWED_IPS'),

    /*
    | Calls per minute per client IP on the web endpoint.
    */
    'rate_limit' => (int) env('OPS_MCP_RATE_LIMIT', 60),

    /*
    | Every tool call is logged (tool name and arguments, never the output)
    | to this channel. Null uses the default channel.
    */
    'log_channel' => env('OPS_MCP_LOG_CHANNEL'),

    /*
    | The handle of the local stdio server: `php artisan mcp:start ops`.
    */
    'local_handle' => 'ops',

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | tail_log reads the files behind your `single` and `daily` log channels
    | and any file in storage/logs by its bare name. Files outside that
    | directory must be allow-listed here by a name of your choosing.
    |
    */

    'logs' => [
        'extra' => [
            // 'nginx-error' => '/var/log/nginx/error.log',
            // 'php-fpm' => '/var/log/php8.3-fpm.log',
        ],
        'max_lines' => 200,
        'max_bytes' => 32768,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Which connection and queue names queue_status reports. Null uses the
    | default connection. Queues a running Horizon supervisor declares are
    | added automatically.
    |
    */

    'queues' => [
        'connection' => env('OPS_MCP_QUEUE_CONNECTION'),
        'names' => ['default'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | Laravel records nothing about when a scheduled task last ran. With this
    | on, the package listens to the scheduler's events and keeps the last
    | run of every task (and the scheduler's last tick) in the cache.
    |
    */

    'scheduler' => [
        'record' => (bool) env('OPS_MCP_RECORD_SCHEDULER', true),
        'ttl_days' => 7,
    ],

];
