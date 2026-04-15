<?php

use Negoziator\HorizonUi\Http\Controllers\HorizonApiController;
use Negoziator\HorizonUi\Http\Controllers\HorizonDashboardController;

Route::middleware(config('horizon-ui.middleware', ['web', 'auth']))
    ->prefix(config('horizon-ui.path', 'horizon-ui'))
    ->name('horizon-ui.')
    ->group(function (): void {

        if (config('horizon-ui.register_dashboard_route', true)) {
            Route::get('/', HorizonDashboardController::class)->name('dashboard');
        }

        if (config('horizon-ui.register_api_routes', true)) {
            Route::prefix('api')->name('api.')->group(function (): void {
                Route::get('stats',                              [HorizonApiController::class, 'index'])->name('stats');
                Route::post('pause',                             [HorizonApiController::class, 'pause'])->name('pause');
                Route::post('continue',                          [HorizonApiController::class, 'continue'])->name('continue');
                Route::post('terminate',                         [HorizonApiController::class, 'terminate'])->name('terminate');
                Route::post('retry/{id}',                        [HorizonApiController::class, 'retry'])->name('retry');
                Route::delete('forget/{id}',                     [HorizonApiController::class, 'forget'])->name('forget');
                Route::delete('flush-failed',                    [HorizonApiController::class, 'flushFailed'])->name('flush-failed');
                Route::delete('flush-pending',                   [HorizonApiController::class, 'flushPending'])->name('flush-pending');
                Route::delete('flush-completed',                 [HorizonApiController::class, 'flushCompleted'])->name('flush-completed');
                Route::delete('flush-batches',                   [HorizonApiController::class, 'flushBatches'])->name('flush-batches');
                Route::post('supervisors/{name}/pause',          [HorizonApiController::class, 'pauseSupervisor'])->name('supervisors.pause');
                Route::post('supervisors/{name}/continue',       [HorizonApiController::class, 'continueSupervisor'])->name('supervisors.continue');
                Route::get('job/{id}',                           [HorizonApiController::class, 'showJob'])->name('job.show');
                Route::get('jobs/{type}',                        [HorizonApiController::class, 'jobs'])->name('jobs');
                Route::get('metrics',                            [HorizonApiController::class, 'metrics'])->name('metrics');
                Route::get('batches',                            [HorizonApiController::class, 'batches'])->name('batches');
                Route::get('batches/{id}',                       [HorizonApiController::class, 'showBatch'])->name('batches.show');
                Route::post('batches/{id}/retry',                [HorizonApiController::class, 'retryBatch'])->name('batches.retry');
            });
        }
    });
