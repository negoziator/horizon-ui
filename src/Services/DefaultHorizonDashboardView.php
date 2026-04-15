<?php

namespace Negoziator\HorizonUi\Services;

use Illuminate\Bus\BatchRepository;
use Illuminate\Database\QueryException;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;

class DefaultHorizonDashboardView implements HorizonDashboardView
{
    public function __construct(
        protected JobRepository $jobs,
        protected MetricsRepository $metrics,
        protected MasterSupervisorRepository $masters,
        protected SupervisorRepository $supervisors,
        protected WorkloadRepository $workload,
        protected BatchRepository $batches,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     jobsPerMinute: float,
     *     failedJobs: int,
     *     recentJobs: int,
     *     processes: int,
     *     pausedMasters: int,
     *     queueWithMaxRuntime: mixed,
     *     queueWithMaxThroughput: mixed
     * }
     */
    public function stats(): array
    {
        return [
            'status' => $this->currentStatus(),
            'jobsPerMinute' => $this->metrics->jobsProcessedPerMinute(),
            'failedJobs' => $this->jobs->countFailed(),
            'recentJobs' => $this->jobs->countCompleted(),
            'processes' => $this->totalProcessCount(),
            'pausedMasters' => $this->totalPausedMasters(),
            'queueWithMaxRuntime' => $this->metrics->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => $this->metrics->queueWithMaximumThroughput(),
        ];
    }

    /**
     * @return array{queues: array<mixed>, workload: array<mixed>}
     */
    public function queueMetrics(): array
    {
        $queues = config('horizon.environments.'.config('app.env'));

        $metrics = collect($queues)->map(function ($config, $name) {
            $queue = $config['queue'] ?? ['default'];

            return [
                'name' => $name,
                'queues' => $queue,
                'processes' => $config['maxProcesses'] ?? 1,
                'memory' => $config['memory'] ?? 512,
                'tries' => $config['tries'] ?? 1,
                'timeout' => $config['timeout'] ?? 60,
            ];
        })->values()->all();

        $workloadData = collect($this->workload->get())
            ->sortBy('name')->values()->all();

        return [
            'queues' => $metrics,
            'workload' => $workloadData,
        ];
    }

    /**
     * @return array<mixed>
     */
    public function recentJobs(): array
    {
        return $this->jobs->getRecent()->take(10)->all();
    }

    /**
     * @return array<array{name: string, status: string, processes: mixed}>
     */
    public function supervisors(): array
    {
        return collect($this->supervisors->all())
            ->map(fn ($supervisor) => [
                'name' => $supervisor->name,
                'status' => $supervisor->status,
                'processes' => $supervisor->processes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<mixed>
     */
    public function recentBatches(): array
    {
        try {
            return collect($this->batches->get(10, null))
                ->map(fn ($batch) => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'totalJobs' => $batch->totalJobs,
                    'pendingJobs' => $batch->pendingJobs,
                    'failedJobs' => $batch->failedJobs,
                    'processedJobs' => $batch->processedJobs(),
                    'progress' => $batch->progress(),
                    'createdAt' => $batch->createdAt->toISOString(),
                    'finishedAt' => $batch->finishedAt?->toISOString(),
                    'cancelledAt' => $batch->cancelledAt?->toISOString(),
                    'finished' => $batch->finished(),
                    'cancelled' => $batch->cancelled(),
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    protected function currentStatus(): string
    {
        if (! $masters = $this->masters->all()) {
            return 'inactive';
        }

        return collect($masters)
            ->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running';
    }

    protected function totalProcessCount(): int
    {
        $supervisors = $this->supervisors->all();

        return collect($supervisors)
            ->reduce(fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0);
    }

    protected function totalPausedMasters(): int
    {
        if (! $masters = $this->masters->all()) {
            return 0;
        }

        return collect($masters)
            ->filter(fn ($master) => $master->status === 'paused')
            ->count();
    }
}
