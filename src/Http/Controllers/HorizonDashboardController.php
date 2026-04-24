<?php

namespace Negoziator\HorizonUi\Http\Controllers;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;

class HorizonDashboardController extends Controller
{
    public function __invoke(HorizonDashboardView $view): Response
    {
        return Inertia::render(config('horizon-ui.view', 'HorizonDashboard'), [
            'horizonStats' => $view->stats(),
            'queueMetrics' => $view->queueMetrics(),
            'recentJobs' => $view->recentJobs(),
            'supervisors' => $view->supervisors(),
            'recentBatches' => $view->recentBatches(),
            'pollingInterval' => config('horizon-ui.polling_interval', 2000),
            'routes' => $this->buildRouteMap(),
        ]);
    }

    /**
     * Build the URL map for all API endpoints.
     * Vue components receive this as a prop and call these URLs directly.
     *
     * @return array<string, string>
     */
    protected function buildRouteMap(): array
    {
        $prefix = config('horizon-ui.path', 'horizon-ui').'/api';

        return [
            'stats' => url($prefix.'/stats'),
            'pause' => url($prefix.'/pause'),
            'continue' => url($prefix.'/continue'),
            'terminate' => url($prefix.'/terminate'),
            'flushFailed' => url($prefix.'/flush-failed'),
            'flushPending' => url($prefix.'/flush-pending'),
            'flushCompleted' => url($prefix.'/flush-completed'),
            'flushBatches' => url($prefix.'/flush-batches'),
            'jobs' => url($prefix.'/jobs'),      // append /{type}
            'job' => url($prefix.'/job'),       // append /{id}
            'retry' => url($prefix.'/retry'),     // append /{id}
            'forget' => url($prefix.'/forget'),    // append /{id}
            'metrics' => url($prefix.'/metrics'),
            'batches' => url($prefix.'/batches'),
            'batchShow' => url($prefix.'/batches'),   // append /{id}
            'batchRetry' => url($prefix.'/batches'),   // append /{id}/retry
            'supervisorPause' => url($prefix.'/supervisors'), // append /{name}/pause
            'supervisorContinue' => url($prefix.'/supervisors'), // append /{name}/continue
            'jobSearch' => url($prefix.'/jobs/search'),
        ];
    }
}
