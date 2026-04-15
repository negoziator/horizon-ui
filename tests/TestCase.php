<?php

namespace Negoziator\HorizonUi\Tests;

use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;
use Negoziator\HorizonUi\HorizonUiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Inertia\ServiceProvider::class,
            HorizonUiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        // Disable auth middleware so tests don't need a real user session.
        // Auth enforcement is verified by checking the default config value.
        $app['config']->set('horizon-ui.middleware', ['web']);
        // Point the view loader at the test stubs directory so Inertia's
        // initial-page-load responses have a valid `app.blade.php` to render.
        $app['config']->set('view.paths', [__DIR__.'/stubs', resource_path('views')]);
        // Don't verify that Vue component files exist on disk during tests.
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
    }

    protected function defineRoutes($router): void
    {
        // Stub the login route so auth redirects resolve cleanly in tests.
        $router->get('login', fn () => response('login', 200))->name('login');
    }

    /**
     * Bind mock implementations of all Horizon repository contracts so that
     * tests can run without a live Redis connection.
     */
    protected function bindHorizonMocks(): void
    {
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->allows('countRecentlyFailed')->andReturn(0);
        $jobs->allows('countRecent')->andReturn(0);
        $jobs->allows('countFailed')->andReturn(0);
        $jobs->allows('countCompleted')->andReturn(0);
        $jobs->allows('getFailed')->andReturn(collect([]));
        $jobs->allows('getPending')->andReturn(collect([]));
        $jobs->allows('getRecent')->andReturn(collect([]));
        $jobs->allows('getJobs')->andReturn(collect([]));
        $jobs->allows('deleteFailed')->andReturn(null);
        $jobs->allows('purge')->andReturn(0);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->allows('jobsProcessedPerMinute')->andReturn(0.0);
        $metrics->allows('queueWithMaximumRuntime')->andReturn(null);
        $metrics->allows('queueWithMaximumThroughput')->andReturn(null);

        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->allows('all')->andReturn([]);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->allows('all')->andReturn([]);

        $workload = Mockery::mock(WorkloadRepository::class);
        $workload->allows('get')->andReturn([]);

        $commandQueue = Mockery::mock(HorizonCommandQueue::class);
        $commandQueue->allows('push')->andReturn(null);

        $batches = Mockery::mock(BatchRepository::class);
        $batches->allows('get')->andReturn([]);
        $batches->allows('find')->andReturn(null);

        $waitCalc = Mockery::mock(WaitTimeCalculator::class);
        $waitCalc->allows('calculate')->andReturn([]);

        $this->app->instance(JobRepository::class, $jobs);
        $this->app->instance(MetricsRepository::class, $metrics);
        $this->app->instance(MasterSupervisorRepository::class, $masters);
        $this->app->instance(SupervisorRepository::class, $supervisors);
        $this->app->instance(WorkloadRepository::class, $workload);
        $this->app->instance(HorizonCommandQueue::class, $commandQueue);
        $this->app->instance(BatchRepository::class, $batches);
        $this->app->instance(WaitTimeCalculator::class, $waitCalc);
    }
}
