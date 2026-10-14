<?php

namespace Rootstuff\OpsMcp\Support;

use Symfony\Component\Process\Process;

/**
 * The box and the app as they are right now: versions, environment, the
 * release on disk, load, memory, disk and the processes that matter.
 * Linux-first (procfs); anything unreadable comes back null, never an error.
 */
final class Vitals
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $loads = sys_getloadavg();

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
                'uptime' => self::uptime(),
                'load' => $loads !== false ? ['1m' => round($loads[0], 2), '5m' => round($loads[1], 2), '15m' => round($loads[2], 2)] : null,
                'cpus' => self::cpus(),
                'memory' => self::memory(),
                'disk' => [
                    'app' => self::disk(base_path()),
                    'storage' => self::disk(storage_path()),
                ],
            ],
            'processes' => [
                'php-fpm' => self::count(['pgrep', '-c', 'php-fpm']),
                'horizon' => self::count(['pgrep', '-f', '-c', 'artisan horizon']),
                'queue-work' => self::count(['pgrep', '-f', '-c', 'queue:work']),
                'nginx' => self::count(['pgrep', '-c', 'nginx']),
                'horizon_master' => self::horizonMaster(),
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

        $out = trim(self::run(['uptime', '-p'], 5));

        return $out !== '' ? $out : null;
    }

    private static function cpus(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

            return $count > 0 ? $count : null;
        }

        return self::count(['sysctl', '-n', 'hw.ncpu']);
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
     * The oldest `artisan horizon` process: how long it has run says whether
     * Horizon restarted without a deploy.
     *
     * @return array{pid: int, uptime_seconds: int, started_at: string}|null
     */
    private static function horizonMaster(): ?array
    {
        $pid = (int) trim(self::run(['pgrep', '-o', '-f', 'artisan horizon$'], 5));

        if ($pid <= 0) {
            return null;
        }

        $elapsed = (int) trim(self::run(['ps', '-o', 'etimes=', '-p', (string) $pid], 5));

        return [
            'pid' => $pid,
            'uptime_seconds' => $elapsed,
            'started_at' => now()->subSeconds($elapsed)->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $argv
     */
    private static function count(array $argv): ?int
    {
        $out = trim(self::run($argv, 5));

        return $out === '' || ! is_numeric($out) ? null : (int) $out;
    }

    /**
     * @param  list<string>  $argv
     */
    private static function run(array $argv, int $timeout): string
    {
        try {
            $process = new Process($argv);
            $process->setTimeout($timeout);
            $process->run();

            return $process->getOutput();
        } catch (\Throwable) {
            return '';
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
