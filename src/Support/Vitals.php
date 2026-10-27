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
        $processes = [
            'php-fpm' => self::count(['pgrep', 'php-fpm']),
            'horizon' => self::count(['pgrep', '-f', 'artisan horizon']),
            'queue-work' => self::count(['pgrep', '-f', 'queue:work']),
            'nginx' => self::count(['pgrep', 'nginx']),
            'horizon_master' => self::horizonMaster(),
        ];
        $notes = [];

        if ($memory === null) {
            $notes[] = 'memory is read from /proc/meminfo, which '.PHP_OS_FAMILY.' does not have.';
        }

        if ($uptime === null) {
            $notes[] = 'uptime needs /proc/uptime or `sysctl kern.boottime`; neither answered.';
        }

        if (array_filter(array_slice($processes, 0, 4), fn (?int $count) => $count !== null) === []) {
            $notes[] = 'process counts need `pgrep` on PATH; it did not answer.';
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
            'processes' => $processes,
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
     * The oldest `artisan horizon` process: how long it has run says whether
     * Horizon restarted without a deploy.
     *
     * @return array{pid: int, uptime_seconds: int, started_at: string}|null
     */
    private static function horizonMaster(): ?array
    {
        [$exit, $out] = self::run(['pgrep', '-o', '-f', 'artisan horizon$'], 5);
        $pid = $exit === 0 ? (int) trim($out) : 0;

        if ($pid <= 0) {
            return null;
        }

        [$exit, $out] = self::run(['ps', '-o', 'etime=', '-p', (string) $pid], 5);
        $elapsed = $exit === 0 ? self::parseElapsed(trim($out)) : null;

        if ($elapsed === null) {
            return null;
        }

        return [
            'pid' => $pid,
            'uptime_seconds' => $elapsed,
            'started_at' => now()->subSeconds($elapsed)->toIso8601String(),
        ];
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
