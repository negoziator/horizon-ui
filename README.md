# Customizable Inertia-powered Horizon dashboard

`negoziator/horizon-ui` drops a fully-featured Horizon dashboard into any Laravel + Inertia application. It is rendered via Inertia (Vue 3) and exposes a complete REST API for queue management — all without requiring Ziggy or Wayfinder.

Features at a glance:

- Live stats: jobs/min, failures, process count, paused supervisors
- Queue metrics and per-supervisor workload
- Recent/failed/pending job browser with retry, forget, and bulk flush
- Batch browser with retry and cancel
- `viewHorizonUi` gate for fine-grained access control
- Optional `horizon-ui:auto-pause` command that pauses idle supervisors automatically
- Publishable Vue components for full frontend customization

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.4 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| Laravel Horizon | ^5.0 |
| inertiajs/inertia-laravel | ^1.0 \| ^2.0 \| ^3.0 |
| **Frontend** | |
| Vue | ^3.0 |
| Tailwind CSS | **v4** |
| @reka-ui/vue | ^2.0 |
| lucide-vue-next | ^0.400+ |

> **Tailwind v4 only.** The bundled components use v4 utility classes. If your app runs Tailwind v3 you will need to publish and adjust the components.

## Installation

```bash
composer require negoziator/horizon-ui
php artisan horizon-ui:install
```

`horizon-ui:install` publishes `config/horizon-ui.php` and prints the dashboard URL.

The dashboard is available at `/horizon-ui` by default. Open it in your browser — you should see the Horizon UI page rendered by Inertia.

### Frontend peer dependencies

The bundled Vue components require `@reka-ui/vue` and `lucide-vue-next`. Install them alongside your other frontend dependencies:

```bash
npm install @reka-ui/vue lucide-vue-next
```

### Inertia page resolution

The `HorizonDashboard` Inertia component must be resolvable by your app. The simplest way is to update your `app.ts` (or `app.js`) to check the vendor path as a fallback:

```ts
resolve: (name) =>
    resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue'))
    ?? resolvePageComponent(
        `./vendor/horizon-ui/pages/${name}.vue`,
        import.meta.glob('./vendor/horizon-ui/pages/**/*.vue'),
    ),
```

This works whether you are using the bundled components or have published them.

## Configuration

After publishing the config file you will find `config/horizon-ui.php`:

```php
return [
    // URL path for the dashboard
    'path' => 'horizon-ui',

    // Middleware applied to all routes (page + API)
    'middleware' => ['web', 'auth'],

    // Inertia component name — override to use your own page
    'view' => 'HorizonDashboard',

    // Set false to disable the dashboard page (API-only usage)
    'register_dashboard_route' => true,

    // Set false to disable all API routes
    'register_api_routes' => true,

    // Frontend polling interval in milliseconds
    'polling_interval' => 2000,

    // Auto-pause idle supervisors via the scheduler
    'auto_pause' => [
        'enabled' => false,
    ],
];
```

## Authorization

The package defines a `viewHorizonUi` gate that defaults to `local` environments only. Override it in your `AppServiceProvider` to fit your app's access rules:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewHorizonUi', fn ($user) => $user->isAdmin());
```

To enforce the gate at the routing layer, add it to the middleware list in `config/horizon-ui.php`:

```php
'middleware' => ['web', 'auth', 'can:viewHorizonUi'],
```

## Customizing the data layer

The `HorizonDashboardView` contract drives all dashboard data. Bind your own implementation to customise what is shown:

```php
use Negoziator\HorizonUi\Contracts\HorizonDashboardView;

$this->app->bind(HorizonDashboardView::class, MyCustomDashboardView::class);
```

Your implementation must satisfy the five methods defined in the contract: `stats()`, `queueMetrics()`, `recentJobs()`, `supervisors()`, and `recentBatches()`.

## Customising the Vue components

Publish the Vue components to make frontend changes:

```bash
php artisan vendor:publish --tag=horizon-ui-vue
```

This copies the five components to `resources/js/vendor/horizon-ui/`. Edit them freely — they will no longer be overwritten on package updates.

### Route URLs in components

The package does not use Ziggy or Wayfinder. All API route URLs are built server-side in `HorizonDashboardController::buildRouteMap()` and passed to the page as an Inertia `routes` prop. Components receive the prop and call URLs directly:

```ts
router.post(props.routes.pause, {}, { preserveScroll: true })
```

If you add custom API routes, extend `buildRouteMap()` in a subclass or override the controller binding.

## Auto-pause command

When `auto_pause.enabled` is `true`, the package schedules `horizon-ui:auto-pause` every minute. The command checks each supervisor's queues and pauses supervisors whose queues have been empty for a configurable period, then resumes them when jobs arrive again.

You can also run it manually:

```bash
php artisan horizon-ui:auto-pause
```

## Testing

```bash
composer install
./vendor/bin/pest
```

## Contributing

Pull requests are welcome. Please open an issue first for significant changes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
