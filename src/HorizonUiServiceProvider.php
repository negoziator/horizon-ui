<?php

namespace Negoziator\HorizonUi;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Negoziator\HorizonUi\Commands\AutoPauseHorizonSupervisors;
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;
use Negoziator\HorizonUi\Services\DefaultHorizonDashboardView;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HorizonUiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('horizon-ui')
            ->hasConfigFile()
            ->hasRoutes(['horizon-ui'])
            ->hasCommand(AutoPauseHorizonSupervisors::class)
            ->hasInstallCommand(function ($command): void {
                $command
                    ->publishConfigFile()
                    ->endWith(function ($cmd): void {
                        $cmd->call('vendor:publish', ['--tag' => 'horizon-ui-vue']);
                        $cmd->newLine();
                        $cmd->info('Horizon UI installed. Dashboard: '.url(config('horizon-ui.path', 'horizon-ui')));
                        $cmd->comment('Next: update your Inertia resolve function in app.ts — see the README for the snippet.');
                    });
            });
    }

    public function packageRegistered(): void
    {
        $this->app->bind(HorizonDashboardView::class, DefaultHorizonDashboardView::class);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/horizon-ui'),
        ], 'horizon-ui-vue');

        Gate::define('viewHorizonUi', fn ($user = null) => app()->environment('local'));

        if (config('horizon-ui.auto_pause.enabled')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('horizon-ui:auto-pause')->everyMinute();
            });
        }
    }
}
