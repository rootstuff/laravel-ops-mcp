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
| `vitals` | Laravel and PHP versions, environment, the release directory, maintenance mode, opcache, host uptime, load, memory, disk; host-wide php-fpm and nginx counts; and this app's own Horizon, `queue:work` and Inertia SSR processes with the Horizon master's age, told apart from other apps on the same box by their working directory under `/proc` (a `releases/<sha>` layout counts every release). Memory comes from `/proc/meminfo`, so it is null off Linux; whatever is null says why in `host.notes`. |
| `tail_log` | The last lines of a log: the app's default channel when you name none, or a channel by name (`single` and `daily`, plus `{channel}-yesterday`), any file in `storage/logs` by name, or a path you allow-list. A plain tail reads the last 256 KB of the file; a case-insensitive `grep` or a `since` (`30m`, `2h`, `1d`, or an ISO time) streams the last 16 MB and keeps the last matches. Warns when another named log was written more recently than the one you read, the stale `laravel.log` beside a live daily file. Redacted before it leaves. |
| `list_logs` | The channels and the files `tail_log` can read, with sizes and last write. |
| `failed_jobs` | Failed jobs over a window grouped by queue, job class and the normalised exception line, most frequent first, plus the newest ten with ids for `queue:retry`. |
| `queue_status` | Pending, delayed and reserved jobs per queue with the age of the oldest. With Horizon: every master and supervisor, processes per queue, and the declared queues no process is on. |
| `scheduler` | Every scheduled task with its expression, next due time and last run, and whether `schedule:run` has ticked recently. |

Laravel keeps no record of past scheduled runs, so the package listens to the
scheduler's own events and keeps the last run of each task in your cache,
plus a tick every time `schedule:run` starts (whether or not anything was
due). That's the one thing it writes.

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

If the app caches its routes (Forge deploys do), rebuild the cache after
turning the endpoint on, and reload PHP-FPM if OPcache has timestamp
validation off, as it does on Forge. Until both happen the route stays a
404 even though `route:list` shows it:

```bash
php artisan route:cache && sudo service php8.3-fpm reload
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
60 a minute by default) sit in front of it; every refusal, the rate limit
included, is a JSON-RPC error. Every tool call is logged with its
arguments, never its output (so don't grep for a secret).

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

PHP 8.2 or newer, Laravel 12.41 or 13, and [laravel/mcp](https://github.com/laravel/mcp) 1.x.
Laravel 11 is out because laravel/mcp 1.x needs `illuminate/json-schema`, which
first shipped in Laravel 12.41.

## Who made this

[Rootstuff](https://rootstuff.io), which makes
[Sentinel](https://sentinel.rootstuff.io), the uptime monitoring service.
This package started as Sentinel's own operator tools and was pulled out so
any Laravel app can have them.

MIT licensed.
