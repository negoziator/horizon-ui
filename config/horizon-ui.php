<?php

return [
    /*
     * The path where the Horizon UI dashboard will be accessible.
     */
    'path' => 'horizon-ui',

    /*
     * Middleware applied to all horizon-ui routes (both page and API).
     */
    'middleware' => ['web', 'auth'],

    /*
     * The Inertia component to render for the dashboard page.
     * Override this to use your own page component.
     * The default is the publishable component at resources/js/pages/HorizonDashboard.vue.
     */
    'view' => 'HorizonDashboard',

    /*
     * Set to false to disable the dashboard page route.
     * Useful for headless (API-only) usage.
     */
    'register_dashboard_route' => true,

    /*
     * Set to false to disable all API routes.
     */
    'register_api_routes' => true,

    /*
     * Polling interval in milliseconds for the frontend dashboard.
     */
    'polling_interval' => 2000,

    /*
     * Auto-pause: automatically pause supervisors when their queues are empty.
     * Enable this to register the horizon-ui:auto-pause command on the scheduler.
     */
    'auto_pause' => [
        'enabled' => false,
    ],
];
