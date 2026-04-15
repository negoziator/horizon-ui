<?php

use Inertia\Testing\AssertableInertia;
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;
use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->app->instance(HorizonDashboardView::class, new class implements HorizonDashboardView {
        public function stats(): array
        {
            return [
                'status' => 'running',
                'jobsPerMinute' => 5.0,
                'failedJobs' => 0,
                'recentJobs' => 10,
                'processes' => 3,
                'pausedMasters' => 0,
                'queueWithMaxRuntime' => null,
                'queueWithMaxThroughput' => null,
            ];
        }

        public function queueMetrics(): array
        {
            return ['queues' => [], 'workload' => []];
        }

        public function recentJobs(): array
        {
            return [];
        }

        public function supervisors(): array
        {
            return [];
        }

        public function recentBatches(): array
        {
            return [];
        }
    });
});

it('renders the dashboard page', function () {
    $response = $this->get('/horizon-ui');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('HorizonDashboard')
    );
});

it('passes routes prop with all expected keys', function () {
    $response = $this->get('/horizon-ui');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('routes')
        ->has('routes.pause')
        ->has('routes.continue')
        ->has('routes.terminate')
        ->has('routes.flushFailed')
        ->has('routes.flushPending')
        ->has('routes.flushCompleted')
        ->has('routes.flushBatches')
        ->has('routes.jobs')
        ->has('routes.job')
        ->has('routes.retry')
        ->has('routes.forget')
        ->has('routes.batches')
        ->has('routes.batchRetry')
        ->has('routes.supervisorPause')
        ->has('routes.supervisorContinue')
    );
});

it('passes polling interval prop', function () {
    config(['horizon-ui.polling_interval' => 5000]);

    $response = $this->get('/horizon-ui');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('pollingInterval', 5000)
    );
});

it('passes all horizon data props', function () {
    $response = $this->get('/horizon-ui');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('horizonStats')
        ->has('queueMetrics')
        ->has('recentJobs')
        ->has('supervisors')
        ->has('recentBatches')
    );
});

it('respects view config', function () {
    config(['horizon-ui.view' => 'CustomHorizonPage']);

    $response = $this->get('/horizon-ui');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CustomHorizonPage')
    );
});

it('routes contain the correct path prefix', function () {
    // buildRouteMap() reads config at request time, so changing it before
    // the request is enough — the route itself stays registered at /horizon-ui.
    config(['horizon-ui.path' => 'custom-horizon']);

    $response = $this->get('/horizon-ui');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('routes')
        ->where('routes.pause', fn ($url) => str_contains($url, 'custom-horizon/api/pause'))
    );
});
