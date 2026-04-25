<?php

namespace Negoziator\HorizonUi;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Negoziator\HorizonUi\Commands\AutoPauseHorizonSupervisors;
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;
use Negoziator\HorizonUi\Services\DefaultHorizonDashboardView;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HorizonUiServiceProvider extends PackageServiceProvider
{
    private const string TAILWIND_SOURCE_DIRECTIVE = "@source '../js/vendor/horizon-ui/**/*.vue';";

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
                        $this->injectTailwindSource($cmd);
                        $cmd->newLine();
                        $cmd->info('Horizon UI installed. Dashboard: '.url(config('horizon-ui.path', 'horizon-ui')));
                        $cmd->comment('Next: update your Inertia resolve function in app.ts — see the README for the snippet.');
                    });
            });
    }

    protected function injectTailwindSource(Command $command): void
    {
        $cssPath = resource_path('css/app.css');
        $directive = self::TAILWIND_SOURCE_DIRECTIVE;

        if (! is_file($cssPath)) {
            $command->error('Could not find resources/css/app.css to register the package\'s Vue files.');
            $command->line('   Add this directive to your Tailwind v4 entry point, or Horizon UI will render unstyled:');
            $command->newLine();
            $command->line("   {$directive}");

            return;
        }

        $contents = file_get_contents($cssPath);

        if (preg_match('/@source\s+[\'"][^\'"]*js\/vendor\/horizon-ui[^\'"]*[\'"]/', $contents) === 1) {
            $command->info('Tailwind @source directive already present in resources/css/app.css.');

            return;
        }

        if (preg_match('/^\s*@import\s+[\'"]tailwindcss[\'"][^;]*;/m', $contents) !== 1) {
            $command->warn('resources/css/app.css has no @import "tailwindcss" line — is this a Tailwind v4 entry point?');
            $command->line("   Add manually where Tailwind scans sources: {$directive}");

            return;
        }

        if (! $command->confirm('Add Tailwind @source directive to resources/css/app.css so Horizon UI components are styled?', true)) {
            $command->warn("Skipped. Add manually to your Tailwind entry point: {$directive}");

            return;
        }

        $updated = preg_replace(
            '/(^\s*@import\s+[\'"]tailwindcss[\'"][^;]*;)/m',
            "\$1\n{$directive}",
            $contents,
            1,
        );

        if ($updated === null || $updated === $contents) {
            $command->error('Failed to inject @source directive into resources/css/app.css.');
            $command->line("   Add manually: {$directive}");

            return;
        }

        file_put_contents($cssPath, $updated);
        $command->info('Registered Horizon UI Vue files in resources/css/app.css.');
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
