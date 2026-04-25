# Customizable Inertia-powered Horizon dashboard

`negoziator/horizon-ui` drops a fully-featured Horizon dashboard into any Laravel + Inertia application. It is rendered via Inertia (Vue 3) and exposes a complete REST API for queue management — all without requiring Ziggy or Wayfinder.

Features at a glance:

- Live stats: jobs/min, failures, process count, paused supervisors
- Queue metrics and per-supervisor workload
- Recent/failed/pending job browser with retry, forget, and bulk flush
- Batch browser with retry and cancel
- **Full-text job search** across class name, queue, tags, and payload content
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
| reka-ui | ^2.0 |
| lucide-vue-next | ^0.400+ |

> **Tailwind v4 only.** The bundled components use v4 utility classes. If your app runs Tailwind v3 you will need to publish and adjust the components.

## Installation

```bash
composer require negoziator/horizon-ui
php artisan horizon-ui:install
```

`horizon-ui:install` publishes `config/horizon-ui.php`, copies the Vue components to `resources/js/vendor/horizon-ui/`, and prints the dashboard URL.

### Frontend peer dependencies

The bundled Vue components require `reka-ui` and `lucide-vue-next`. Install them alongside your other frontend dependencies:

```bash
npm install reka-ui lucide-vue-next
```

### Tailwind v4 setup

The bundled components use `dark:` utility variants, but Tailwind v4 does not register a `dark` variant out of the box — you have to declare one yourself. Add it to your Tailwind entry point (typically `resources/css/app.css`) so the dashboard responds to OS-level dark-mode preference:

```css
@import "tailwindcss";
@custom-variant dark (@media (prefers-color-scheme: dark));
```

If your app uses a class-based dark-mode toggle (`<html class="dark">`) instead, declare the variant accordingly:

```css
@custom-variant dark (&:where(.dark, .dark *));
```

Without one of these declarations, the dashboard renders in light mode regardless of OS preference.

### Inertia page resolution

The `HorizonDashboard` Inertia component is placed in `resources/js/vendor/horizon-ui/pages/` (done automatically by `horizon-ui:install`). Vite's `import.meta.glob` doesn't scan that path by default, so you need to add it to your resolve function in `app.ts` (or `app.js`):

```ts
// at the top of app.ts:
// import type { DefineComponent } from 'vue';

resolve: async (name) => {
    const vendorPages = import.meta.glob<DefineComponent>('./vendor/horizon-ui/pages/**/*.vue');
    const vendorPath = `./vendor/horizon-ui/pages/${name}.vue`;

    if (vendorPath in vendorPages) {
        return await resolvePageComponent(vendorPath, vendorPages);
    }

    return await resolvePageComponent(
        `./pages/${name}.vue`,
        import.meta.glob<DefineComponent>('./pages/**/*.vue'),
    );
},
```

> **Why not `??`?** `resolvePageComponent` throws when a component isn't found, so the `??` operator never gets to evaluate the fallback. The `in` check is required.

After publishing (`vendor:publish --tag=horizon-ui-vue`), or after a package update where you want to pull in new component versions, re-run the publish command. Your edited copies are never overwritten without `--force`.

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

    // Job search: max jobs scanned per request
    'search' => [
        'scan_limit' => 1000,
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

## Job search

The package exposes a search endpoint that scans jobs in PHP and filters across class name, queue name, tags, and the decoded payload:

```
GET /{path}/api/jobs/search
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `q` | string | — | Search term (required, min 2 chars) |
| `type` | string | `recent` | Job set: `recent`, `failed`, `pending`, `completed` |
| `queue` | string | — | Restrict to a specific queue name |
| `limit` | int | `search.page_size` | Max results to return (max `100`) |
| `cursor` | int | `0` | Offset to resume from (use `next_cursor` from the previous response) |

The response includes a `next_cursor` value for fetching the next page; it is `null` when results are exhausted.

The `jobSearch` URL is included in the Inertia `routes` prop so Vue components can call it directly:

```ts
axios.get(props.routes.jobSearch, { params: { q: 'SendEmail', type: 'failed' } })
```

### Search performance

The search fetches jobs from Horizon's Redis sorted sets in pages of 50 (Horizon's fixed page size), stopping once the requested number of results is found or the configured scan ceiling is reached. For large queues, keep queries specific and use the `queue` filter to narrow the scan.

Both the default page size and the scan ceiling are configurable in `config/horizon-ui.php`:

```php
'search' => [
    'page_size'  => 25,   // default results per request (overridable via ?limit=)
    'scan_limit' => 1000, // max jobs scanned per request
],
```

For installations with tens of thousands of jobs, document that search is intended for development and small-to-medium production queues. Very large queues may need an external index (e.g. Redis Search).

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
