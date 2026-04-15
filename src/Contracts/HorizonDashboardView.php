<?php

namespace Negoziator\HorizonUi\Contracts;

interface HorizonDashboardView
{
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
    public function stats(): array;

    /**
     * @return array{queues: array<mixed>, workload: array<mixed>}
     */
    public function queueMetrics(): array;

    /**
     * @return array<mixed>
     */
    public function recentJobs(): array;

    /**
     * @return array<array{name: string, status: string, processes: mixed}>
     */
    public function supervisors(): array;

    /**
     * @return array<mixed>
     */
    public function recentBatches(): array;
}
