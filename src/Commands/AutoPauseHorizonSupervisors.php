<?php

namespace Negoziator\HorizonUi\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\SupervisorCommands\ContinueWorking;
use Laravel\Horizon\SupervisorCommands\Pause;

class AutoPauseHorizonSupervisors extends Command
{
    #[\Override]
    protected $signature = 'horizon-ui:auto-pause';

    #[\Override]
    protected $description = 'Auto-pause idle Horizon supervisors and resume those with pending jobs';

    public function handle(SupervisorRepository $repository, HorizonCommandQueue $commandQueue): int
    {
        $supervisors = $repository->all();

        if (empty($supervisors)) {
            return self::SUCCESS;
        }

        foreach ($supervisors as $supervisor) {
            $queues = $this->resolveQueues($supervisor);
            $pending = $this->countPendingJobs($supervisor->options['connection'] ?? 'redis', $queues);

            if ($supervisor->status === 'running' && $pending === 0) {
                $commandQueue->push($supervisor->name, Pause::class);
                $this->line("Paused supervisor [{$supervisor->name}] — no pending jobs.");
            } elseif ($supervisor->status === 'paused' && $pending > 0) {
                $commandQueue->push($supervisor->name, ContinueWorking::class);
                $this->line("Resumed supervisor [{$supervisor->name}] — {$pending} pending job(s).");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the queue names for the given supervisor.
     *
     * Uses the `processes` map (keyed as `connection:queue`) which is always
     * present for a live supervisor, falling back to `options.queue` for
     * supervisors that have no active worker processes yet.
     *
     * @return string[]
     */
    private function resolveQueues(\stdClass $supervisor): array
    {
        if (! empty($supervisor->processes)) {
            return collect(array_keys($supervisor->processes))
                ->map(fn (string $key) => last(explode(':', $key, 2)))
                ->unique()
                ->values()
                ->all();
        }

        $queues = $supervisor->options['queue'] ?? [];

        return is_array($queues) ? $queues : explode(',', (string) $queues);
    }

    /**
     * Count all pending + reserved (running) jobs across the given queues.
     *
     * `Queue::size()` includes pending, reserved, and delayed jobs in Redis,
     * so a size of 0 means the queue is truly empty.
     */
    private function countPendingJobs(string $connection, array $queues): int
    {
        return (int) array_sum(
            array_map(
                fn (string $queue) => Queue::connection($connection)->size($queue),
                $queues,
            )
        );
    }
}
