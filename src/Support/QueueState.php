<?php

namespace Rootstuff\OpsMcp\Support;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

/**
 * The queues as they stand: pending, delayed and reserved jobs per queue
 * with the age of the oldest pending job, and, when Horizon runs the app,
 * every supervisor with the processes it has on each queue and the queues
 * no process is on.
 */
final class QueueState
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $connectionName = (string) (config('ops-mcp.queues.connection') ?: config('queue.default'));
        $driver = (string) config("queue.connections.{$connectionName}.driver", 'unknown');
        $horizon = self::horizon();

        $names = array_values(array_unique(array_merge(
            (array) config('ops-mcp.queues.names', ['default']),
            $horizon['declared_queues'] ?? [],
        )));

        $queues = [];

        if ($driver !== 'sync' && $driver !== 'null') {
            try {
                $connection = Queue::connection($connectionName);
            } catch (Throwable $e) {
                $connection = null;
                $error = Redactor::redact($e->getMessage());
            }

            foreach ($names as $queue) {
                if ($connection === null) {
                    $queues[$queue] = ['error' => $error ?? 'connection unavailable'];

                    continue;
                }

                try {
                    $oldest = method_exists($connection, 'creationTimeOfOldestPendingJob') ? $connection->creationTimeOfOldestPendingJob($queue) : null;
                    $queues[$queue] = [
                        'pending' => method_exists($connection, 'pendingSize') ? (int) $connection->pendingSize($queue) : (int) $connection->size($queue),
                        'delayed' => method_exists($connection, 'delayedSize') ? (int) $connection->delayedSize($queue) : null,
                        'reserved' => method_exists($connection, 'reservedSize') ? (int) $connection->reservedSize($queue) : null,
                        'oldest_pending_age_seconds' => $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : null,
                        'workers' => $horizon['workers'][$queue] ?? null,
                    ];
                } catch (Throwable $e) {
                    $queues[$queue] = ['error' => Redactor::redact($e->getMessage())];
                }
            }
        }

        return [
            'connection' => $connectionName,
            'driver' => $driver,
            'queues' => $queues,
            'horizon' => $horizon === null ? null : [
                'installed' => true,
                'masters' => $horizon['masters'],
                'supervisors' => $horizon['supervisors'],
                'starved' => $horizon['starved'],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Horizon's live supervisor records, when Horizon is installed: masters
     * (one per box), supervisors with their declared queues and processes
     * per queue, and the declared queues no process is on. The auto balancer
     * starves the last pool of a supervisor whose queues times minProcesses
     * exceed maxProcesses, and the queue then looks served while nothing
     * drains it.
     *
     * @return array<string, mixed>|null
     */
    private static function horizon(): ?array
    {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            return null;
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $supervisors = app(SupervisorRepository::class)->all();
        } catch (Throwable $e) {
            return ['masters' => [], 'supervisors' => [], 'starved' => [], 'workers' => [], 'declared_queues' => [], 'error' => Redactor::redact($e->getMessage())];
        }

        $out = ['masters' => [], 'supervisors' => [], 'starved' => [], 'workers' => [], 'declared_queues' => []];

        foreach ($masters as $master) {
            $out['masters'][] = [
                'name' => (string) $master->name,
                'status' => (string) ($master->status ?? 'unknown'),
                'pid' => $master->pid ?? null,
            ];
        }

        foreach ($supervisors as $supervisor) {
            $declared = array_values(array_filter(array_map('trim', explode(',', (string) (((array) ($supervisor->options ?? []))['queue'] ?? '')))));
            $processes = [];

            foreach ((array) ($supervisor->processes ?? []) as $connectionQueue => $count) {
                $queueList = str_contains($connectionQueue, ':') ? explode(':', $connectionQueue, 2)[1] : $connectionQueue;

                foreach (explode(',', $queueList) as $queue) {
                    $queue = trim($queue);
                    $processes[$queue] = ($processes[$queue] ?? 0) + (int) $count;
                    $out['workers'][$queue] = ($out['workers'][$queue] ?? 0) + (int) $count;
                }
            }

            $starved = array_values(array_filter($declared, fn (string $queue) => ($processes[$queue] ?? 0) === 0));
            $out['starved'] = array_values(array_unique(array_merge($out['starved'], $starved)));
            $out['declared_queues'] = array_values(array_unique(array_merge($out['declared_queues'], $declared)));

            $out['supervisors'][] = [
                'name' => (string) ($supervisor->name ?? ''),
                'master' => (string) ($supervisor->master ?? ''),
                'status' => (string) ($supervisor->status ?? 'unknown'),
                'queues' => $declared,
                'processes' => $processes,
                'total_processes' => array_sum((array) ($supervisor->processes ?? [])),
                'starved' => $starved,
            ];
        }

        return $out;
    }
}
