<?php

namespace Rootstuff\OpsMcp\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Cache;
use Rootstuff\OpsMcp\Support\Redactor;
use Rootstuff\OpsMcp\Support\SchedulerState;
use Throwable;

/**
 * Keeps the last run of every scheduled task, and the scheduler's last
 * tick, in the cache. Cheap (one cache write per task per run) and the only
 * way to answer "when did this last run?" without reading cron logs.
 */
final class RecordScheduledTask
{
    public function starting(ScheduledTaskStarting $event): void
    {
        $this->write(SchedulerState::TICK_KEY, now()->getTimestamp());
        $this->merge($event, ['started_at' => now()->toIso8601String(), 'status' => 'running']);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->merge($event, [
            'finished_at' => now()->toIso8601String(),
            'runtime_seconds' => round($event->runtime, 2),
            'exit_code' => $event->task->exitCode,
            'status' => $event->task->exitCode === null || $event->task->exitCode === 0 ? 'ok' : 'failed',
        ]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->merge($event, [
            'finished_at' => now()->toIso8601String(),
            'status' => 'failed',
            'error' => Redactor::redact(mb_substr($event->exception->getMessage(), 0, 300)),
        ]);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->merge($event, ['skipped_at' => now()->toIso8601String(), 'status' => 'skipped']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed|ScheduledTaskSkipped $event, array $data): void
    {
        $key = SchedulerState::taskKey($event->task);
        $current = Cache::get($key);

        $this->write($key, array_merge(is_array($current) ? $current : [], $data));
    }

    private function write(string $key, mixed $value): void
    {
        try {
            Cache::put($key, $value, now()->addDays(max(1, (int) config('ops-mcp.scheduler.ttl_days', 7))));
        } catch (Throwable) {
            // A broken cache must never break the scheduler.
        }
    }
}
