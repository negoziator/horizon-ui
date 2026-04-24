<?php

namespace Negoziator\HorizonUi\Http\Controllers;

use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\ContinueWorking;
use Laravel\Horizon\SupervisorCommands\Pause;
use Laravel\Horizon\SupervisorCommands\Terminate;
use Laravel\Horizon\WaitTimeCalculator;
use Negoziator\HorizonUi\Services\HorizonJobSearchService;

class HorizonApiController extends Controller
{
    public function __construct(
        public JobRepository $jobs,
        public MetricsRepository $metrics,
        public MasterSupervisorRepository $masters,
        public SupervisorRepository $supervisors,
        public WorkloadRepository $workload,
        public HorizonCommandQueue $commandQueue,
        public BatchRepository $batches,
    ) {}

    /**
     * Get the key performance stats for the dashboard.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'failedJobs' => $this->jobs->countRecentlyFailed(),
            'jobsPerMinute' => $this->metrics->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => $this->metrics->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => $this->metrics->queueWithMaximumThroughput(),
            'recentJobs' => $this->jobs->countRecent(),
            'status' => $this->currentStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ]);
    }

    /**
     * Pause the Horizon master supervisor.
     */
    public function pause(): RedirectResponse
    {
        $this->pushCommandToMasters(Pause::class);

        return redirect()->back()->with('success', 'Horizon has been paused');
    }

    /**
     * Continue the Horizon master supervisor.
     */
    public function continue(): RedirectResponse
    {
        $this->pushCommandToMasters(ContinueWorking::class);

        return redirect()->back()->with('success', 'Horizon is now continuing');
    }

    /**
     * Terminate the Horizon master supervisor.
     */
    public function terminate(): RedirectResponse
    {
        $this->pushCommandToMasters(Terminate::class, ['status' => 0]);

        return redirect()->back()->with('success', 'Horizon is terminating and will restart');
    }

    /**
     * Retry a failed job.
     */
    public function retry(string $id): RedirectResponse
    {
        try {
            Artisan::call('queue:retry', ['id' => [$id]]);

            return redirect()->back()->with('success', 'Job retry initiated');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to retry job: '.$e->getMessage());
        }
    }

    /**
     * Forget (delete) a failed job.
     */
    public function forget(string $id): RedirectResponse
    {
        try {
            $this->jobs->deleteFailed($id);

            return redirect()->back()->with('success', 'Job deleted');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete job: '.$e->getMessage());
        }
    }

    /**
     * Flush (delete) all failed jobs.
     */
    public function flushFailed(): RedirectResponse
    {
        try {
            $failed = $this->jobs->getFailed();
            $count = $failed->count();

            foreach ($failed as $job) {
                $this->jobs->deleteFailed($job->id);
            }

            return redirect()->back()->with('success', "{$count} failed jobs deleted");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to flush jobs: '.$e->getMessage());
        }
    }

    /**
     * Flush (clear) all pending jobs from all queues.
     */
    public function flushPending(): RedirectResponse
    {
        try {
            $queues = collect(config('horizon.defaults'))
                ->merge(config('horizon.environments.'.config('app.env'), []))
                ->pluck('queue')
                ->flatten()
                ->filter()
                ->push('default')
                ->unique()
                ->values();

            foreach ($queues as $queue) {
                Artisan::call('queue:clear', [
                    'connection' => 'redis',
                    '--queue' => $queue,
                    '--force' => true,
                ]);
            }

            return redirect()->back()->with('success', 'Pending jobs flushed from all queues');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to flush pending jobs: '.$e->getMessage());
        }
    }

    /**
     * Clear all completed/recent job records from Horizon.
     */
    public function flushCompleted(): RedirectResponse
    {
        try {
            $redis = app(Factory::class)->connection('horizon');

            $redis->del('completed_jobs');
            $redis->del('silenced_jobs');
            $redis->del('recent_jobs');

            return redirect()->back()->with('success', 'Completed jobs cleared');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to flush completed jobs: '.$e->getMessage());
        }
    }

    /**
     * Cancel and prune all batches.
     */
    public function flushBatches(): RedirectResponse
    {
        try {
            $count = 0;
            $batches = $this->batches->get(1000, null);

            foreach ($batches as $batch) {
                if (! $batch->finished()) {
                    $batch->cancel();
                }
                $batch->delete();
                $count++;
            }

            return redirect()->back()->with('success', "{$count} batches cleared");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to flush batches: '.$e->getMessage());
        }
    }

    /**
     * Pause a specific supervisor.
     */
    public function pauseSupervisor(string $name): RedirectResponse
    {
        $this->pushCommandToSupervisor($name, Pause::class);

        return redirect()->back()->with('success', "Supervisor {$name} has been paused");
    }

    /**
     * Continue a specific supervisor.
     */
    public function continueSupervisor(string $name): RedirectResponse
    {
        $this->pushCommandToSupervisor($name, ContinueWorking::class);

        return redirect()->back()->with('success', "Supervisor {$name} is now continuing");
    }

    /**
     * Get a single job's full details.
     */
    public function showJob(string $id): JsonResponse
    {
        $job = $this->jobs->getJobs([$id])->first();

        if (! $job) {
            return response()->json(['job' => null], 404);
        }

        $payload = json_decode((string) $job->payload, true) ?? [];

        return response()->json([
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'status' => $job->status,
                'queue' => $job->queue,
                'connection' => $job->connection ?? null,
                'exception' => $job->exception ?? null,
                'context' => $job->context ?? null,
                'failed_at' => $job->failed_at ?? null,
                'completed_at' => $job->completed_at ?? null,
                'reserved_at' => $job->reserved_at ?? null,
                'retried_by' => isset($job->retried_by) ? json_decode($job->retried_by, true) : null,
                'payload' => [
                    'id' => $payload['id'] ?? null,
                    'displayName' => $payload['displayName'] ?? null,
                    'tags' => $payload['tags'] ?? [],
                    'pushedAt' => $payload['pushedAt'] ?? null,
                    'attempts' => $payload['attempts'] ?? null,
                    'maxTries' => $payload['maxTries'] ?? null,
                    'maxExceptions' => $payload['maxExceptions'] ?? null,
                    'timeout' => $payload['timeout'] ?? null,
                    'failOnTimeout' => $payload['failOnTimeout'] ?? false,
                    'backoff' => $payload['backoff'] ?? null,
                    'data' => $this->formatPayloadData($payload['data'] ?? null),
                ],
            ],
        ]);
    }

    /**
     * Search jobs across name, queue, tags, and payload.
     */
    public function searchJobs(Request $request, HorizonJobSearchService $search): JsonResponse
    {
        $request->validate([
            'q'      => 'required|string|min:2|max:200',
            'type'   => 'nullable|in:recent,failed,pending,completed',
            'queue'  => 'nullable|string|max:100',
            'limit'  => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|integer|min:0',
        ]);

        $results = $search->search(
            query:  $request->string('q')->toString(),
            type:   $request->input('type', 'recent'),
            queue:  $request->input('queue'),
            limit:  (int) $request->input('limit', 25),
            cursor: (int) $request->input('cursor', 0),
        );

        return response()->json($results);
    }

    /**
     * Get jobs by type (pending, completed, failed).
     */
    public function jobs(string $type): JsonResponse
    {
        $jobs = match ($type) {
            'pending' => $this->jobs->getPending()->take(50),
            'completed' => $this->jobs->getRecent()->take(50),
            'failed' => $this->jobs->getFailed()->take(50),
            default => collect([]),
        };

        return response()->json([
            'type' => $type,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Get queue metrics.
     */
    public function metrics(): JsonResponse
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
        })->values();

        return response()->json([
            'queues' => $metrics,
            'workload' => $this->getWorkload(),
        ]);
    }

    /**
     * Get all batches.
     */
    public function batches(Request $request): JsonResponse
    {
        try {
            $batches = $this->batches->get(50, $request->query('before_id') ?: null);
        } catch (QueryException) {
            $batches = [];
        }

        return response()->json([
            'batches' => $batches,
        ]);
    }

    /**
     * Get the details of a batch by ID.
     */
    public function showBatch(string $id): JsonResponse
    {
        $batch = $this->batches->find($id);
        $failedJobs = null;

        if ($batch) {
            $failedJobs = $this->jobs->getJobs($batch->failedJobIds);
        }

        return response()->json([
            'batch' => $batch,
            'failedJobs' => $failedJobs,
        ]);
    }

    /**
     * Retry all failed jobs in a batch.
     */
    public function retryBatch(string $id): RedirectResponse
    {
        try {
            $batch = $this->batches->find($id);

            if ($batch) {
                if (! empty($batch->failedJobIds)) {
                    Artisan::call('queue:retry', ['id' => $batch->failedJobIds]);
                }

                return redirect()->back()->with('success', 'Batch retry initiated');
            }

            return redirect()->back()->with('error', 'Batch not found');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to retry batch: '.$e->getMessage());
        }
    }

    /**
     * Push a command to all master supervisors via the Redis command queue.
     *
     * @param  class-string  $command
     * @param  array<string, mixed>  $options
     */
    protected function pushCommandToMasters(string $command, array $options = []): void
    {
        foreach ($this->masters->all() as $master) {
            $this->commandQueue->push(
                MasterSupervisor::commandQueueFor($master->name),
                $command,
                $options,
            );
        }
    }

    /**
     * Push a command to a specific supervisor via the Redis command queue.
     *
     * @param  class-string  $command
     * @param  array<string, mixed>  $options
     */
    protected function pushCommandToSupervisor(string $name, string $command, array $options = []): void
    {
        $supervisor = collect($this->supervisors->all())
            ->first(fn ($s) => str_ends_with((string) $s->name, ':'.$name));

        if ($supervisor) {
            $this->commandQueue->push($supervisor->name, $command, $options);
        }
    }

    protected function totalProcessCount(): int
    {
        $supervisors = $this->supervisors->all();

        return collect($supervisors)
            ->reduce(fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0);
    }

    protected function currentStatus(): string
    {
        if (! $masters = $this->masters->all()) {
            return 'inactive';
        }

        return collect($masters)
            ->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running';
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

    protected function getWorkload(): array
    {
        return collect($this->workload->get())
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Unserialize the payload data command into a readable array.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    protected function formatPayloadData(?array $data): ?array
    {
        if (! $data || ! isset($data['command'])) {
            return $data;
        }

        try {
            $command = unserialize($data['command'], ['allowed_classes' => false]);
            $data['command'] = $this->convertToArray($command);
        } catch (\Throwable) {
            // Keep raw string if unserialize fails
        }

        return $data;
    }

    /**
     * Recursively convert an unserialized object tree to an associative array.
     * Handles __PHP_Incomplete_Class objects and mangled property names.
     */
    protected function convertToArray(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 10) {
            return '(truncated)';
        }

        if (is_object($value)) {
            $array = (array) $value;
            $result = [];

            foreach ($array as $key => $val) {
                $cleanKey = preg_replace('/^\x00[^\x00]+\x00/', '', (string) $key);

                if ($cleanKey === '__PHP_Incomplete_Class_Name') {
                    $result['__class'] = $val;

                    continue;
                }

                $result[$cleanKey] = $this->convertToArray($val, $depth + 1);
            }

            return $result;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->convertToArray($item, $depth + 1), $value);
        }

        return $value;
    }
}
