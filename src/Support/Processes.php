<?php

namespace Rootstuff\OpsMcp\Support;

use Symfony\Component\Process\Process;

/**
 * This app's long-running processes, read from /proc: the Horizon master,
 * supervisors and workers, queue:work, the Inertia SSR wrapper and its node
 * child, schedule:work, Octane, Reverb, and anything else running from the
 * app's directory. Each one carries its age, the release it runs from, and
 * the TCP ports it listens on, which is what answers "did it restart on
 * deploy?" and "which old process still holds the port?".
 *
 * Processes are scoped by working directory under the site root, the same
 * rule vitals uses, so other apps on the box never appear. Without /proc
 * the listing falls back to command lines that mention the app's path.
 */
final class Processes
{
    public const MAX = 200;

    /** USER_HZ is fixed at 100 for userspace on Linux, whatever the kernel's HZ. */
    private const CLOCK_TICKS = 100;

    /**
     * @return array{root: string, current_release: string|null, scoped: bool, count: int, stale_count: int, by_kind: array<string, int>, by_release: array<string, int>, processes: list<array<string, mixed>>, notes: list<string>}
     */
    public static function collect(?string $procRoot = null, ?string $appRoot = null, ?string $kind = null): array
    {
        $proc = rtrim($procRoot ?? '/proc', '/');
        $root = rtrim($appRoot ?? Vitals::appRoot((string) (realpath(base_path()) ?: base_path())), '/');
        $currentRelease = self::currentRelease($root);
        $notes = [];

        if (is_file($proc.'/uptime')) {
            $scoped = true;
            [$processes, $portsIncomplete] = self::fromProc($proc, $root, $currentRelease);

            if ($portsIncomplete) {
                $notes[] = 'listening ports are incomplete: some processes belong to another user, whose sockets /proc hides.';
            }
        } else {
            $scoped = false;
            $processes = self::fromPs($root, $currentRelease);
            $notes[] = 'process listing needs /proc; on '.PHP_OS_FAMILY.' this is a best-effort match on command lines that mention the app path, without working directories or ports.';
        }

        if ($kind !== null && $kind !== '') {
            $processes = array_values(array_filter($processes, fn (array $p) => $p['kind'] === $kind));
        }

        usort($processes, fn (array $a, array $b) => [$a['kind'], $b['age_seconds']] <=> [$b['kind'], $a['age_seconds']]);

        if (count($processes) > self::MAX) {
            $notes[] = 'listing capped at '.self::MAX.' processes.';
            $processes = array_slice($processes, 0, self::MAX);
        }

        $byKind = [];
        $byRelease = [];
        $stale = 0;

        foreach ($processes as $p) {
            $byKind[$p['kind']] = ($byKind[$p['kind']] ?? 0) + 1;
            $label = $p['release'] ?? '(no release dir)';
            $byRelease[$label] = ($byRelease[$label] ?? 0) + 1;
            $stale += $p['stale'] ? 1 : 0;
        }

        ksort($byKind);
        ksort($byRelease);

        if ($stale > 0) {
            $notes[] = "{$stale} process(es) run from a release other than current ({$currentRelease}): started before the last deploy and never restarted.";
        }

        return [
            'root' => $root,
            'current_release' => $currentRelease,
            'scoped' => $scoped,
            'count' => count($processes),
            'stale_count' => $stale,
            'by_kind' => $byKind,
            'by_release' => $byRelease,
            'processes' => $processes,
            'notes' => $notes,
        ];
    }

    /**
     * The release name `<root>/current` points at, when the app uses a
     * releases/<name> layout; null otherwise.
     */
    public static function currentRelease(string $root): ?string
    {
        $target = @readlink($root.'/current');

        if ($target === false) {
            $real = realpath($root.'/current');
            $target = $real === false ? null : $real;
        }

        return $target === null ? null : self::releaseOf($root, $target);
    }

    /**
     * The release directory a path sits in, relative to the root's
     * releases/ directory, or null when the path is not under one.
     */
    public static function releaseOf(string $root, string $path): ?string
    {
        $path = rtrim($path, '/');

        if (! str_starts_with($path, '/')) {
            $path = $root.'/'.$path; // readlink of a relative symlink
        }

        if (preg_match('#^'.preg_quote($root, '#').'/releases/([^/]+)#', $path, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Seconds a /proc stat line's process has run, given the host uptime.
     * The stat line's comm field may contain spaces, so parse after the
     * closing parenthesis: field 3 (state) is index 0 from there.
     *
     * @return array{ppid: int, age_seconds: int}|null
     */
    public static function parseStat(string $stat, float $uptime): ?array
    {
        $close = strrpos($stat, ')');

        if ($close === false) {
            return null;
        }

        $fields = explode(' ', trim(substr($stat, $close + 1)));

        if (count($fields) < 20) {
            return null;
        }

        $startTicks = (float) $fields[19]; // field 22: starttime

        return [
            'ppid' => (int) $fields[1], // field 4
            'age_seconds' => max(0, (int) round($uptime - $startTicks / self::CLOCK_TICKS)),
        ];
    }

    /**
     * What a command line is, for grouping. Checked most specific first.
     */
    public static function kind(string $command): string
    {
        return match (true) {
            preg_match('/artisan horizon$/', $command) === 1 => 'horizon-master',
            str_contains($command, 'artisan horizon:supervisor') => 'horizon-supervisor',
            str_contains($command, 'artisan horizon:work') => 'horizon-worker',
            str_contains($command, 'queue:work'), str_contains($command, 'queue:listen') => 'queue-worker',
            str_contains($command, 'inertia:start-ssr') => 'ssr',
            preg_match('~ssr/ssr\.m?js~', $command) === 1 => 'ssr-node',
            str_contains($command, 'schedule:work') => 'scheduler',
            str_contains($command, 'schedule:run') => 'scheduler-tick',
            str_contains($command, 'octane:') => 'octane',
            str_contains($command, 'reverb:') => 'reverb',
            str_contains($command, 'pulse:') => 'pulse',
            str_contains($command, 'mcp:start') => 'mcp',
            preg_match('/artisan(\s|$)/', $command) === 1 => 'artisan',
            preg_match('#(^|/)node(\s|$)#', $command) === 1 => 'node',
            default => 'other',
        };
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool} processes and whether any fd directory was unreadable
     */
    private static function fromProc(string $proc, string $root, ?string $currentRelease): array
    {
        $uptime = (float) explode(' ', trim((string) @file_get_contents($proc.'/uptime')))[0];
        $listening = self::listeningSockets($proc);
        $processes = [];
        $portsIncomplete = false;
        $self = getmypid();

        foreach (scandir($proc) ?: [] as $entry) {
            if (! ctype_digit($entry) || (int) $entry === $self) {
                continue;
            }

            $dir = "{$proc}/{$entry}";
            $cwd = @readlink("{$dir}/cwd");

            if ($cwd === false || ! str_starts_with(rtrim($cwd, '/').'/', $root.'/')) {
                continue;
            }

            $command = trim(str_replace("\0", ' ', (string) @file_get_contents("{$dir}/cmdline")));

            if ($command === '') {
                continue;
            }

            $stat = self::parseStat((string) @file_get_contents("{$dir}/stat"), $uptime);

            if ($stat === null) {
                continue;
            }

            $fds = @scandir("{$dir}/fd");
            $ports = [];

            if ($fds === false) {
                $portsIncomplete = true;
            } else {
                foreach ($fds as $fd) {
                    $target = @readlink("{$dir}/fd/{$fd}");

                    if ($target !== false && preg_match('/^socket:\[(\d+)\]$/', $target, $m) === 1 && isset($listening[$m[1]])) {
                        $ports[] = $listening[$m[1]];
                    }
                }
            }

            $ports = array_values(array_unique($ports));
            sort($ports);
            $release = self::releaseOf($root, $cwd);

            $processes[] = self::entry((int) $entry, $stat['ppid'], $stat['age_seconds'], $command, $cwd, $release, $currentRelease, $ports);
        }

        return [$processes, $portsIncomplete];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fromPs(string $root, ?string $currentRelease): array
    {
        try {
            $ps = new Process(['ps', '-axo', 'pid=,ppid=,etime=,command=']);
            $ps->setTimeout(5);
            $ps->run();
            $out = $ps->isSuccessful() ? $ps->getOutput() : '';
        } catch (\Throwable) {
            $out = '';
        }

        $processes = [];

        foreach (preg_split('/\R/', trim($out), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/', $line, $m) !== 1 || ! str_contains($m[4], $root)) {
                continue;
            }

            if ((int) $m[1] === getmypid()) {
                continue;
            }

            $age = Vitals::parseElapsed($m[3]) ?? 0;
            $processes[] = self::entry((int) $m[1], (int) $m[2], $age, trim($m[4]), null, self::releaseOf($root, self::pathIn($m[4], $root) ?? ''), $currentRelease, []);
        }

        return $processes;
    }

    private static function pathIn(string $command, string $root): ?string
    {
        return preg_match('#'.preg_quote($root, '#').'\S*#', $command, $m) === 1 ? $m[0] : null;
    }

    /**
     * @param  list<int>  $ports
     * @return array<string, mixed>
     */
    private static function entry(int $pid, int $ppid, int $age, string $command, ?string $cwd, ?string $release, ?string $currentRelease, array $ports): array
    {
        return [
            'pid' => $pid,
            'ppid' => $ppid,
            'kind' => self::kind($command),
            'age_seconds' => $age,
            'age' => Vitals::duration($age),
            'started_at' => now()->subSeconds($age)->toIso8601String(),
            'cwd' => $cwd,
            'release' => $release,
            'stale' => $release !== null && $currentRelease !== null && $release !== $currentRelease,
            'listening' => $ports,
            'command' => Redactor::redact(mb_substr($command, 0, 240)),
        ];
    }

    /**
     * Socket inode => local port for every TCP socket in LISTEN state.
     *
     * @return array<string, int>
     */
    private static function listeningSockets(string $proc): array
    {
        $map = [];

        foreach (['tcp', 'tcp6'] as $table) {
            $lines = preg_split('/\R/', (string) @file_get_contents("{$proc}/net/{$table}"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            array_shift($lines); // header

            foreach ($lines as $line) {
                $cols = preg_split('/\s+/', trim($line)) ?: [];

                if (count($cols) < 10 || $cols[3] !== '0A') {
                    continue;
                }

                $port = hexdec((string) substr($cols[1], (int) strrpos($cols[1], ':') + 1));
                $map[$cols[9]] = (int) $port;
            }
        }

        return $map;
    }
}
