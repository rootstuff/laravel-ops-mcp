<?php

namespace Rootstuff\OpsMcp\Support;

use Symfony\Component\Process\Process;

/**
 * The box and the app as they are right now: versions, environment, the
 * release on disk, load, memory, disk and the processes that matter.
 * Linux-first (procfs), with sysctl and pgrep fallbacks for macOS and BSD;
 * anything still unreadable comes back null and says so in host.notes.
 */
final class Vitals
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $loads = sys_getloadavg();
        $uptime = self::uptime();
        $memory = self::memory();
        $host = [
            'php-fpm' => self::count(['pgrep', 'php-fpm']),
            'nginx' => self::count(['pgrep', 'nginx']),
        ];
        $app = self::appProcesses();
        $notes = [];

        if ($memory === null) {
            $notes[] = 'memory is read from /proc/meminfo, which '.PHP_OS_FAMILY.' does not have.';
        }

        if ($uptime === null) {
            $notes[] = 'uptime needs /proc/uptime or `sysctl kern.boottime`; neither answered.';
        }

        if ($host['php-fpm'] === null && $host['nginx'] === null) {
            $notes[] = 'process counts need `pgrep` on PATH; it did not answer.';
        }

        if (! $app['scoped']) {
            $notes[] = 'app process counts are host-wide on '.PHP_OS_FAMILY.': telling this app\'s processes from other apps\' needs /proc.';
        }

        return [
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'release' => basename((string) realpath(base_path())),
                'maintenance_mode' => app()->isDownForMaintenance(),
                'config_cached' => app()->configurationIsCached(),
                'routes_cached' => app()->routesAreCached(),
                'opcache' => function_exists('opcache_get_status') ? (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false) : null,
            ],
            'host' => [
                'hostname' => gethostname(),
                'os' => PHP_OS_FAMILY,
                'uptime' => $uptime,
                'load' => $loads !== false ? ['1m' => round($loads[0], 2), '5m' => round($loads[1], 2), '15m' => round($loads[2], 2)] : null,
                'cpus' => self::cpus(),
                'memory' => $memory,
                'disk' => [
                    'app' => self::disk(base_path()),
                    'storage' => self::disk(storage_path()),
                ],
                'notes' => $notes,
            ],
            'processes' => [
                'host' => $host,
                'app' => $app,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private static function uptime(): ?string
    {
        if (is_readable('/proc/uptime')) {
            $seconds = (int) explode(' ', trim((string) file_get_contents('/proc/uptime')))[0];

            return self::duration($seconds);
        }

        // BSD and macOS: `{ sec = 1760000000, usec = 0 } Mon Oct 13 ...`
        [$exit, $out] = self::run(['sysctl', '-n', 'kern.boottime'], 5);

        if ($exit === 0 && preg_match('/sec\s*=\s*(\d+)/', $out, $m) === 1) {
            return self::duration(max(0, time() - (int) $m[1]));
        }

        return null;
    }

    private static function cpus(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

            return $count > 0 ? $count : null;
        }

        [$exit, $out] = self::run(['sysctl', '-n', 'hw.ncpu'], 5);

        return $exit === 0 && is_numeric(trim($out)) ? (int) trim($out) : null;
    }

    /**
     * @return array<string, int>|null
     */
    private static function memory(): ?array
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $info = [];

        foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+)/', $line, $m)) {
                $info[$m[1]] = (int) round($m[2] / 1024);
            }
        }

        return $info === [] ? null : [
            'total_mb' => $info['MemTotal'] ?? 0,
            'available_mb' => $info['MemAvailable'] ?? 0,
            'swap_used_mb' => ($info['SwapTotal'] ?? 0) - ($info['SwapFree'] ?? 0),
        ];
    }

    /**
     * @return array<string, float|int>|null
     */
    private static function disk(string $path): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total == 0) {
            return null;
        }

        return [
            'total_gb' => round($total / 1e9, 1),
            'free_gb' => round($free / 1e9, 1),
            'used_percent' => (int) round(100 * ($total - $free) / $total),
        ];
    }

    /**
     * This app's own long-running processes, counted from the same listing
     * the processes tool returns (scoped by working directory under /proc;
     * best-effort by command line elsewhere, with `scoped` false).
     *
     * @return array{root: string, scoped: bool, horizon: int, queue_work: int, inertia_ssr: int, horizon_master: array{pid: int, uptime_seconds: int, started_at: string}|null}
     */
    public static function appProcesses(): array
    {
        $listing = Processes::collect();
        $counts = ['horizon' => 0, 'queue_work' => 0, 'inertia_ssr' => 0];
        $master = null;

        foreach ($listing['processes'] as $process) {
            $kind = (string) $process['kind'];

            if (str_starts_with($kind, 'horizon')) {
                $counts['horizon']++;
            } elseif ($kind === 'queue-worker') {
                $counts['queue_work']++;
            } elseif ($kind === 'ssr' || $kind === 'ssr-node') {
                $counts['inertia_ssr']++;
            }

            if ($kind === 'horizon-master' && ($master === null || $process['age_seconds'] > $master['uptime_seconds'])) {
                $master = ['pid' => (int) $process['pid'], 'uptime_seconds' => (int) $process['age_seconds'], 'started_at' => (string) $process['started_at']];
            }
        }

        return ['root' => $listing['root'], 'scoped' => $listing['scoped']] + $counts + ['horizon_master' => $master];
    }

    /**
     * The directory that is "this app" for process scoping. Zero-downtime
     * layouts run from `<site>/releases/<sha>` behind a `<site>/current`
     * symlink, and processes started before the last deploy still sit in the
     * previous release, so both collapse to `<site>`.
     */
    public static function appRoot(string $basePath): string
    {
        $basePath = rtrim($basePath, '/');

        if (basename(dirname($basePath)) === 'releases') {
            return dirname($basePath, 2);
        }

        if (basename($basePath) === 'current') {
            return dirname($basePath);
        }

        return $basePath;
    }

    /**
     * How many processes a pgrep matches. pgrep prints one pid a line and
     * exits 1 for no match (`-c` is Linux-only, so the lines are counted
     * here). Anything else, pgrep missing included, is null.
     *
     * @param  list<string>  $argv
     */
    private static function count(array $argv): ?int
    {
        [$exit, $out] = self::run($argv, 5);

        return match ($exit) {
            0 => count(preg_split('/\R/', trim($out), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            1 => 0,
            default => null,
        };
    }

    /**
     * Seconds in a `ps -o etime` value: `[[dd-]hh:]mm:ss`.
     */
    public static function parseElapsed(string $etime): ?int
    {
        if (preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d{1,2}):(\d{2})$/', $etime, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 86400 + ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) $m[4];
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: int, 1: string} exit code (-1 when it could not run) and stdout
     */
    private static function run(array $argv, int $timeout): array
    {
        try {
            $process = new Process($argv);
            $process->setTimeout($timeout);
            $process->run();

            return [$process->getExitCode() ?? -1, $process->getOutput()];
        } catch (\Throwable) {
            return [-1, ''];
        }
    }

    public static function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return trim(($days ? "{$days}d " : '').($hours ? "{$hours}h " : '')."{$minutes}m");
    }
}
