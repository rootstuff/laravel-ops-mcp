<?php

namespace Rootstuff\OpsMcp\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The configuration mistakes that produce most production surprises, as a
 * checklist with a verdict per line: debug on in production, a missing key,
 * caches not built, drivers that only make sense locally, services the app
 * cannot reach, directories it cannot write, a scheduler that is not
 * ticking, long-running processes left on an old release. Reports driver
 * names and verdicts, never values.
 */
final class ConfigSanity
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(?string $procRoot = null, ?string $appRoot = null): array
    {
        $env = (string) config('app.env');
        $production = $env === 'production';
        $checks = [];

        $checks[] = self::check('app.env', 'ok', $env);
        $checks[] = self::check('app.debug', (bool) config('app.debug') && $production ? 'fail' : 'ok', (bool) config('app.debug') ? 'on' : 'off', $production && config('app.debug') ? 'Debug mode is on in production: stack traces and config leak to visitors.' : null);
        $checks[] = self::check('app.key', (string) config('app.key') === '' ? 'fail' : 'ok', (string) config('app.key') === '' ? 'missing' : 'set', (string) config('app.key') === '' ? 'No APP_KEY: sessions and encrypted values will not work.' : null);
        $checks[] = self::check('app.url', 'ok', (string) config('app.url'));
        $checks[] = self::check('maintenance_mode', app()->isDownForMaintenance() ? 'warn' : 'ok', app()->isDownForMaintenance() ? 'down' : 'up');

        $checks[] = self::check('config_cached', app()->configurationIsCached() ? 'ok' : ($production ? 'warn' : 'ok'), app()->configurationIsCached() ? 'yes' : 'no', $production && ! app()->configurationIsCached() ? 'Run `php artisan config:cache` in production.' : null);
        $checks[] = self::check('routes_cached', app()->routesAreCached() ? 'ok' : ($production ? 'warn' : 'ok'), app()->routesAreCached() ? 'yes' : 'no', $production && ! app()->routesAreCached() ? 'Run `php artisan route:cache` in production.' : null);
        $checks[] = self::check('opcache', ! function_exists('opcache_get_status') ? 'warn' : ((bool) (@opcache_get_status(false)['opcache_enabled'] ?? false) ? 'ok' : ($production ? 'warn' : 'ok')), function_exists('opcache_get_status') ? ((bool) (@opcache_get_status(false)['opcache_enabled'] ?? false) ? 'enabled' : 'disabled') : 'not available');

        $drivers = [
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
            'session' => (string) config('session.driver'),
            'mail' => (string) config('mail.default'),
            'logging' => (string) config('logging.default'),
            'filesystem' => (string) config('filesystems.default'),
            'broadcasting' => (string) config('broadcasting.default'),
            'database' => (string) config('database.default'),
        ];

        foreach ($drivers as $service => $driver) {
            $local = in_array($driver, ['array', 'sync', 'log', 'null'], true);
            $checks[] = self::check("driver.{$service}", $production && $local && $service !== 'broadcasting' && $service !== 'logging' ? 'warn' : 'ok', $driver, $production && $local && $service !== 'broadcasting' && $service !== 'logging' ? "The {$driver} driver is for local work, not production." : null);
        }

        $checks[] = self::probe('database', function () {
            DB::connection()->getPdo();

            return 'reachable';
        });
        $checks[] = self::probe('cache', function () {
            $key = 'ops-mcp:probe:'.bin2hex(random_bytes(4));
            Cache::put($key, 1, 10);
            $ok = Cache::get($key) === 1;
            Cache::forget($key);

            return $ok ? 'reachable' : throw new \RuntimeException('a value written to the cache did not read back');
        });

        if (in_array('redis', [config('cache.stores.'.config('cache.default').'.driver'), config('queue.connections.'.config('queue.default').'.driver'), config('session.driver')], true)) {
            $checks[] = self::probe('redis', function () {
                Redis::connection()->command('ping');

                return 'reachable';
            });
        }

        foreach (['storage/logs' => storage_path('logs'), 'storage/framework' => storage_path('framework'), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $dir) {
            $checks[] = self::check("writable.{$label}", is_dir($dir) && is_writable($dir) ? 'ok' : 'fail', is_dir($dir) ? (is_writable($dir) ? 'writable' : 'read-only') : 'missing');
        }

        $scheduler = SchedulerState::collect();
        $checks[] = self::check(
            'scheduler',
            ! ($scheduler['recording'] ?? false) ? 'ok' : (($scheduler['scheduler']['running'] ?? false) ? 'ok' : 'warn'),
            ! ($scheduler['recording'] ?? false) ? 'not recorded' : (($scheduler['scheduler']['running'] ?? false) ? 'ticking' : 'no recent tick'),
            ($scheduler['recording'] ?? false) && ! ($scheduler['scheduler']['running'] ?? false) ? 'No `schedule:run` in the last '.SchedulerState::STALE_AFTER_SECONDS.' seconds. Check the cron entry.' : null,
        );

        $checks[] = self::processesCheck(Processes::collect($procRoot, $appRoot));

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return [
            'summary' => $summary,
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Long-running processes (SSR, Horizon, queue workers) still running
     * from a release older than `current` serve old code until restarted:
     * the deploy looked fine and the site did not change.
     *
     * @param  array<string, mixed>  $listing
     * @return array{check: string, status: string, value: string, note: string|null}
     */
    private static function processesCheck(array $listing): array
    {
        if (! ($listing['scoped'] ?? false)) {
            return self::check('processes', 'ok', 'not scoped', 'Process checks need /proc (Linux).');
        }

        $count = (int) ($listing['count'] ?? 0);
        $stale = (int) ($listing['stale_count'] ?? 0);

        if ($count === 0) {
            return self::check('processes', 'ok', 'none');
        }

        if ($stale === 0) {
            return self::check('processes', 'ok', "{$count} on current release");
        }

        $old = array_keys(array_filter((array) ($listing['by_release'] ?? []), fn ($n, $release) => $release !== ($listing['current_release'] ?? null) && $release !== '(no release dir)', ARRAY_FILTER_USE_BOTH));
        $kinds = array_keys(array_filter((array) ($listing['by_kind'] ?? [])));

        return self::check(
            'processes',
            'warn',
            "{$stale} of {$count} from old releases",
            sprintf(
                '%d process(es) (%s) still run from release(s) %s while current is %s: they serve old code until restarted. See the processes tool.',
                $stale,
                implode(', ', $kinds),
                implode(', ', array_map(fn ($r) => substr((string) $r, 0, 12), $old)),
                substr((string) ($listing['current_release'] ?? ''), 0, 12),
            ),
        );
    }

    /**
     * @return array{check: string, status: string, value: string, note: string|null}
     */
    private static function check(string $name, string $status, string $value, ?string $note = null): array
    {
        return ['check' => $name, 'status' => $status, 'value' => $value, 'note' => $note];
    }

    /**
     * @return array{check: string, status: string, value: string, note: string|null}
     */
    private static function probe(string $name, callable $probe): array
    {
        try {
            return self::check("reachable.{$name}", 'ok', (string) $probe());
        } catch (Throwable $e) {
            return self::check("reachable.{$name}", 'fail', 'unreachable', Redactor::redact(mb_substr($e->getMessage(), 0, 300)));
        }
    }
}
