# laravel-ops-mcp

A read-only MCP server inside your Laravel app, so your agent can read the
logs, the queues, the failed jobs, the scheduler and the box without anyone
opening SSH.

Install it, set a token, and ask Claude Code (or any MCP client) "why did the
nightly import not run?" or "what's in the log from the last deploy?" The
answer comes from the app itself.

## What it exposes

Seven tools, every one read-only, nothing that takes a path or a command:

| Tool | Answers |
|---|---|
| `config_sanity` | The checklist behind most production surprises: debug on in production, a missing `APP_KEY`, config and route caches not built, `array` or `sync` drivers in production, whether the database, cache and Redis answer, whether `storage` and `bootstrap/cache` are writable, whether the scheduler is ticking. Driver names and verdicts only, never values. |
| `vitals` | Laravel and PHP versions, environment, the release directory, maintenance mode, opcache, host uptime, load, memory, disk, and counts of php-fpm, Horizon, `queue:work` and nginx processes with the Horizon master's age. |
| `tail_log` | The last lines of a log channel (`single` and `daily` channels, plus `{channel}-yesterday`), any file in `storage/logs` by name, or a path you allow-list. Case-insensitive grep over the last 16 MB. Redacted before it leaves. |
| `list_logs` | The channels and the files `tail_log` can read, with sizes and last write. |
| `failed_jobs` | Failed jobs over a window grouped by queue, job class and the normalised exception line, most frequent first, plus the newest ten with ids for `queue:retry`. |
| `queue_status` | Pending, delayed and reserved jobs per queue with the age of the oldest. With Horizon: every master and supervisor, processes per queue, and the declared queues no process is on. |
| `scheduler` | Every scheduled task with its expression, next due time and last run, and whether `schedule:run` has ticked recently. |

Laravel keeps no record of past scheduled runs, so the package listens to the
scheduler's own events and keeps the last run of each task in your cache.
That's the one thing it writes.

## Install

```bash
composer require rootstuff/laravel-ops-mcp
```

### Local, over stdio

Nothing to configure. Whoever can run artisan on a box can already read
everything the server reads.

```bash
claude mcp add laravel-ops -- php artisan mcp:start ops
```

### Remote, over HTTP

Generate a token, put it in `.env`, and turn the endpoint on:

```bash
php artisan ops-mcp:token
```

```dotenv
OPS_MCP_ENABLED=true
OPS_MCP_TOKEN=<the generated token>
```

Then connect:

```bash
claude mcp add --transport http laravel-ops https://your-app.example/mcp/ops \
  --header "Authorization: Bearer <the generated token>"
```

## Safety

The web endpoint is off by default and refuses every request until a token
of at least 32 characters is configured. The token is compared in constant
time. An optional IP allow-list (`OPS_MCP_ALLOWED_IPS`, comma-separated
addresses or CIDR ranges) and a per-IP rate limit (`OPS_MCP_RATE_LIMIT`,
60 a minute by default) sit in front of it. Every tool call is logged with
its arguments, never its output.

Log names resolve to a channel, a bare file name inside `storage/logs`, or
a name you allow-listed in the config. A path, `..`, or anything else comes
back as "unknown log". Every line of log output and every error message
passes through a redactor that strips bearer tokens, labelled passwords and
secrets, cloud and payment keys, and credentials inside connection URLs.

Everything reads through the application. If the app is down, this server
is down with it. It answers for 500s, stuck queues, a cron that stopped and
a full disk, not for a dead host.

## Configuration

```bash
php artisan vendor:publish --tag=ops-mcp-config
```

`config/ops-mcp.php` covers the endpoint path, the token, the allow-list,
the rate limit, the log channel for the call log, extra log files by name,
which queue connection and queue names to report, and whether to record
scheduler runs.

## Requirements

PHP 8.2 or newer, Laravel 11, 12 or 13, and [laravel/mcp](https://github.com/laravel/mcp) 1.x.

## Who made this

[Rootstuff](https://rootstuff.io), which makes
[Sentinel](https://sentinel.rootstuff.io), the uptime monitoring service.
This package started as Sentinel's own operator tools and was pulled out so
any Laravel app can have them.

MIT licensed.
