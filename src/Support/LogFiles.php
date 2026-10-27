<?php

namespace Rootstuff\OpsMcp\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The log files an agent may read: the files behind the app's `single` and
 * `daily` channels, every file in storage/logs by its bare name, and the
 * paths the config allow-lists under a name. Nothing here takes a path from
 * the caller, so the worst a bad argument can do is "unknown log".
 */
final class LogFiles
{
    /** How far back from the end of a file a plain tail reads. */
    private const TAIL_WINDOW = 262144;

    /**
     * How far back a grep reads, streamed line by line so memory stays flat
     * however large the window. A busy daily log runs to tens of megabytes,
     * and the tail window alone would cover the last few minutes only.
     */
    private const GREP_WINDOW = 16777216;

    /**
     * Named logs: channel names resolved to today's file, `{channel}-yesterday`
     * for daily channels, and the config's extras.
     *
     * @return array<string, string>
     */
    public static function named(): array
    {
        $named = [];

        foreach ((array) config('logging.channels', []) as $name => $channel) {
            $driver = (string) ($channel['driver'] ?? '');
            $path = (string) ($channel['path'] ?? '');

            if ($path === '' || ! in_array($driver, ['single', 'daily'], true)) {
                continue;
            }

            if ($driver === 'daily') {
                $named[$name] = self::dailyPath($path, Carbon::now());
                $named["{$name}-yesterday"] = self::dailyPath($path, Carbon::now()->subDay());
            } else {
                $named[$name] = $path;
            }
        }

        foreach ((array) config('ops-mcp.logs.extra', []) as $name => $path) {
            if (is_string($name) && is_string($path) && $path !== '') {
                $named[$name] = $path;
            }
        }

        return $named;
    }

    /**
     * The log tail_log reads when the caller names none: the app's default
     * channel, followed through a `stack` to its first file-backed member,
     * then `single`, then `daily`, then whatever is named first. Null when
     * the app has no file-backed channel and nothing is allow-listed.
     */
    public static function defaultLog(): ?string
    {
        $named = self::named();
        $channels = (array) config('logging.channels', []);
        $queue = [(string) config('logging.default', '')];
        $seen = [];

        while ($queue !== []) {
            $name = array_shift($queue);

            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            if (isset($named[$name])) {
                return $name;
            }

            if (($channels[$name]['driver'] ?? null) === 'stack') {
                foreach ((array) ($channels[$name]['channels'] ?? []) as $member) {
                    $queue[] = (string) $member;
                }
            }
        }

        foreach (['single', 'daily'] as $fallback) {
            if (isset($named[$fallback])) {
                return $fallback;
            }
        }

        return array_key_first($named);
    }

    /**
     * Every .log file in storage/logs, newest first.
     *
     * @return list<array{name: string, size_bytes: int, modified_at: string}>
     */
    public static function files(): array
    {
        $files = [];

        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            $files[] = [
                'name' => basename($path),
                'size_bytes' => (int) filesize($path),
                'modified_at' => date('c', (int) filemtime($path)),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /**
     * The path a log name stands for, or null when the name is not one the
     * package knows. A bare file name (no separators) maps into storage/logs
     * only.
     */
    public static function resolve(string $log): ?string
    {
        $named = self::named();

        if (isset($named[$log])) {
            return $named[$log];
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.log$/', $log) === 1 && ! str_contains($log, '..')) {
            return storage_path('logs/'.$log);
        }

        return null;
    }

    /**
     * The last lines of a log, optionally only those containing a string,
     * redacted before they leave. A null log reads the default (see
     * defaultLog). A plain tail reads the last 256 KB of the file; a grep
     * streams the last 16 MB.
     *
     * @return array<string, mixed>
     */
    public static function tail(?string $log, int $lines = 50, ?string $grep = null, ?string $since = null): array
    {
        $sinceAt = null;

        if ($since !== null && $since !== '') {
            $sinceAt = self::parseSince($since);

            if ($sinceAt === null) {
                return ['log' => $log, 'since' => $since, 'error' => 'Unreadable since. Use a window like 30m, 2h or 1d, or an ISO-8601 time.'];
            }
        }

        if ($log === null || $log === '') {
            $log = self::defaultLog();

            if ($log === null) {
                return ['log' => null, 'error' => 'No default log: the app has no single or daily channel and nothing is allow-listed under ops-mcp.logs.extra. Name a file from list_logs instead.'];
            }
        }

        $path = self::resolve($log);

        if ($path === null) {
            return ['log' => $log, 'error' => 'Unknown log. Known names: '.implode(', ', array_keys(self::named())).', or a file name from storage/logs (see list_logs).'];
        }

        if (! is_file($path)) {
            return ['log' => $log, 'path' => $path, 'error' => 'File does not exist.'];
        }

        if (! is_readable($path)) {
            return ['log' => $log, 'path' => $path, 'error' => 'File is not readable by the PHP user.'];
        }

        $maxLines = max(1, (int) config('ops-mcp.logs.max_lines', 200));
        $maxBytes = max(1024, (int) config('ops-mcp.logs.max_bytes', 32768));
        $lines = max(1, min($lines, $maxLines));
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['log' => $log, 'path' => $path, 'error' => 'Could not open the file.'];
        }

        $grepping = $grep !== null && $grep !== '';
        $streaming = $grepping || $sinceAt !== null;
        $start = max(0, $size - ($streaming ? self::GREP_WINDOW : self::TAIL_WINDOW));
        fseek($handle, $start);

        if ($start > 0) {
            fgets($handle); // the first line of a mid-file read is partial
        }

        $matched = null;

        if ($streaming) {
            // Keep only the last N matches while streaming, so a window full
            // of matches never builds a 16 MB array. A `since` cut-off keys
            // off each entry's leading timestamp; continuation lines (stack
            // traces) follow the entry they belong to.
            $selected = [];
            $matched = $grepping ? 0 : null;
            $keeping = $sinceAt === null;

            while (($line = fgets($handle)) !== false) {
                if ($sinceAt !== null) {
                    $stamp = self::timestamp($line);

                    if ($stamp !== null) {
                        $keeping = $stamp->greaterThanOrEqualTo($sinceAt);
                    }

                    if (! $keeping) {
                        continue;
                    }
                }

                if ($grepping) {
                    if (stripos($line, $grep) === false) {
                        continue;
                    }

                    $matched++;
                }

                $selected[] = rtrim($line, "\r\n");

                if (count($selected) > $lines) {
                    array_shift($selected);
                }
            }

            fclose($handle);
        } else {
            $chunk = (string) stream_get_contents($handle);
            fclose($handle);
            $all = $chunk === '' ? [] : (preg_split('/\r?\n/', rtrim($chunk, "\r\n")) ?: []);
            $selected = array_slice($all, -$lines);
        }

        $content = Redactor::redact(implode("\n", $selected));
        $truncated = false;

        if (strlen($content) > $maxBytes) {
            $content = substr($content, -$maxBytes);
            $content = (string) substr($content, (int) strpos($content, "\n") + 1);
            $truncated = true;
        }

        return [
            'log' => $log,
            'path' => $path,
            'size_bytes' => $size,
            'lines' => count($selected),
            'truncated' => $truncated,
            'matched' => $matched,
            'since' => $sinceAt?->toIso8601String(),
            'searched_bytes' => $size - $start,
            'note' => self::staleNote($log, $path),
            'content' => $content,
        ];
    }

    /**
     * A warning when another named log was written more recently than the
     * one being read: the classic trap is a stale laravel.log beside the
     * daily file the app actually logs to now.
     */
    public static function staleNote(string $log, string $path): ?string
    {
        $readAt = (int) @filemtime($path);
        $newest = null;

        foreach (self::named() as $name => $candidate) {
            if ($name === $log || $candidate === $path || ! is_file($candidate)) {
                continue;
            }

            $writtenAt = (int) @filemtime($candidate);

            if ($writtenAt > $readAt + 60 && ($newest === null || $writtenAt > $newest[1])) {
                $newest = [$name, $writtenAt];
            }
        }

        if ($newest === null) {
            return null;
        }

        return sprintf(
            '%s was last written %s ago; %s was written %s ago. The app may be logging there now.',
            $log,
            Vitals::duration(max(0, time() - $readAt)),
            $newest[0],
            Vitals::duration(max(0, time() - $newest[1])),
        );
    }

    /**
     * A `since` argument as a point in time: a window like 30m, 2h or 1d
     * back from now, or anything Carbon parses, in the app's timezone.
     */
    public static function parseSince(string $since): ?Carbon
    {
        $since = trim($since);

        if (preg_match('/^(\d+)\s*([mhd])$/i', $since, $m) === 1) {
            $n = (int) $m[1];

            return match (strtolower($m[2])) {
                'm' => now()->subMinutes($n),
                'h' => now()->subHours($n),
                default => now()->subDays($n),
            };
        }

        try {
            return Carbon::parse($since, config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The timestamp a log entry opens with (`[2026-10-27 02:17:28] ...`,
     * with optional fraction and offset), or null for a continuation line.
     */
    public static function timestamp(string $line): ?Carbon
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)(Z|[+-]\d{2}:?\d{2})?\]/', $line, $m) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($m[1].($m[2] ?? ''), config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function dailyPath(string $path, Carbon $date): string
    {
        $info = pathinfo($path);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        return $info['dirname'].'/'.$info['filename'].'-'.$date->toDateString().$extension;
    }
}
