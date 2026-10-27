<?php

namespace Rootstuff\OpsMcp\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The schedule as the app declares it, with when each task last ran. Laravel
 * records nothing about past runs, so the package's listener stamps every
 * start, finish, failure and skip into the cache (see RecordScheduledTask),
 * along with the scheduler's last tick, stamped every time `schedule:run`
 * starts whether or not anything was due. No tick in a few minutes means
 * the cron entry is missing or stopped, which is the finding behind most
 * "my job never ran" questions.
 */
final class SchedulerState
{
    public const TICK_KEY = 'ops-mcp:scheduler:last-tick';

    /** A tick older than this and the scheduler is reported as not running. */
    public const STALE_AFTER_SECONDS = 180;

    public static function taskKey(Event $event): string
    {
        return 'ops-mcp:scheduler:task:'.sha1($event->mutexName());
    }

    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $cacheError = null;

        try {
            $tick = Cache::get(self::TICK_KEY);
        } catch (Throwable $e) {
            // A cache the app cannot read (the database store without its
            // table, Redis down) must not take the schedule listing with it.
            $tick = null;
            $cacheError = Redactor::redact($e->getMessage());
        }

        $tickAge = is_numeric($tick) ? max(0, now()->getTimestamp() - (int) $tick) : null;

        $tasks = [];

        try {
            $events = app(Schedule::class)->events();
        } catch (Throwable $e) {
            return ['error' => Redactor::redact($e->getMessage())];
        }

        foreach ($events as $event) {
            try {
                $last = $cacheError === null ? Cache::get(self::taskKey($event)) : null;
            } catch (Throwable) {
                $last = null;
            }

            try {
                $next = $event->nextRunDate()->toIso8601String();
            } catch (Throwable) {
                $next = null;
            }

            $tasks[] = [
                'description' => Redactor::redact(self::describe($event)),
                'expression' => $event->expression,
                'timezone' => $event->timezone ? (string) (is_string($event->timezone) ? $event->timezone : $event->timezone->getName()) : null,
                'next_due_at' => $next,
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'run_in_background' => (bool) $event->runInBackground,
                'one_server' => (bool) $event->onOneServer,
                'last_run' => is_array($last) ? $last : null,
            ];
        }

        return [
            'recording' => (bool) config('ops-mcp.scheduler.record', true),
            'scheduler' => [
                'last_tick_at' => is_numeric($tick) ? Carbon::createFromTimestamp((int) $tick)->toIso8601String() : null,
                'last_tick_age_seconds' => $tickAge,
                'running' => $tickAge !== null && $tickAge <= self::STALE_AFTER_SECONDS,
                'cache_error' => $cacheError,
            ],
            'tasks' => $tasks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private static function describe(Event $event): string
    {
        $summary = (string) $event->getSummaryForDisplay();

        // "'/usr/bin/php8.3' 'artisan' backup:run" reads better as its artisan form.
        if (preg_match("/'artisan'\s+(.+)$/", $summary, $m)) {
            return 'artisan '.trim($m[1], " '");
        }

        return $summary;
    }
}
