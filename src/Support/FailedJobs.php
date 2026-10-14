<?php

namespace Rootstuff\OpsMcp\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Failed jobs over a window, grouped by what went wrong rather than listed
 * one by one: the exception's first line with its volatile parts (query
 * strings, timings, release hashes) normalised away, so one cause is one
 * row however many times it fired.
 */
final class FailedJobs
{
    private const SCAN_LIMIT = 5000;

    /**
     * @return array<string, mixed>
     */
    public static function collect(int $hours = 24, int $limit = 20): array
    {
        $hours = max(1, min(24 * 30, $hours));
        $limit = max(1, min(100, $limit));
        $since = now()->subHours($hours);

        $connection = config('queue.failed.database');
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            $rows = DB::connection($connection ?: null)
                ->table($table)
                ->where('failed_at', '>=', $since)
                ->orderByDesc('failed_at')
                ->limit(self::SCAN_LIMIT)
                ->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (Throwable $e) {
            return ['error' => Redactor::redact($e->getMessage()), 'window_hours' => $hours];
        }

        $groups = [];
        $latest = [];

        foreach ($rows as $row) {
            $error = self::normalise(strtok((string) $row->exception, "\n") ?: '');
            $job = self::jobName((string) $row->payload);
            $key = $row->queue.'|'.$job.'|'.$error;

            $groups[$key] ??= ['queue' => $row->queue, 'job' => $job, 'error' => mb_substr($error, 0, 300), 'failures' => 0, 'latest_at' => null, 'example_id' => $row->id];
            $groups[$key]['failures']++;
            $groups[$key]['latest_at'] ??= Carbon::parse($row->failed_at)->toIso8601String();

            if (count($latest) < 10) {
                $latest[] = [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'queue' => $row->queue,
                    'job' => $job,
                    'error' => mb_substr($error, 0, 300),
                    'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
                ];
            }
        }

        usort($groups, fn (array $a, array $b) => $b['failures'] <=> $a['failures']);

        return [
            'window_hours' => $hours,
            'since' => $since->toIso8601String(),
            'total_failures' => count($rows),
            'scan_capped' => count($rows) >= self::SCAN_LIMIT,
            'by_error' => array_slice(array_values($groups), 0, $limit),
            'latest' => $latest,
            'hint' => count($rows) > 0 ? 'Retry one with `php artisan queue:retry <id>` or all with `queue:retry all`; inspect one with `queue:failed`.' : null,
        ];
    }

    /**
     * The stable part of an exception line: no query strings, no timings,
     * no release hashes, no secrets.
     */
    public static function normalise(string $error): string
    {
        $error = (string) preg_replace('/\?[^\s)]*/', '', $error);
        $error = (string) preg_replace('/\bafter \d+ ms\b/', 'after N ms', $error);
        $error = (string) preg_replace('#/releases/[0-9a-f]{7,40}/#', '/releases/*/', $error);

        return Redactor::redact(trim($error));
    }

    private static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? (string) ($decoded['displayName'] ?? $decoded['job'] ?? 'unknown') : 'unknown';
    }
}
