<?php

use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->bindHorizonMocks();
});

it('returns stats', function () {
    $response = $this->getJson('/horizon-ui/api/stats');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'jobsPerMinute',
            'failedJobs',
            'recentJobs',
            'processes',
            'pausedMasters',
            'wait',
        ]);

    expect($response->json('status'))->toBe('inactive');
});

it('can pause horizon', function () {
    $response = $this->post('/horizon-ui/api/pause');

    $response->assertRedirect();
});

it('can continue horizon', function () {
    $response = $this->post('/horizon-ui/api/continue');

    $response->assertRedirect();
});

it('can get jobs by type', function () {
    $response = $this->getJson('/horizon-ui/api/jobs/pending');

    $response->assertOk()
        ->assertJsonStructure(['type', 'jobs']);

    expect($response->json('type'))->toBe('pending');
    expect($response->json('jobs'))->toBeArray();
});

it('returns empty jobs for unknown type', function () {
    $response = $this->getJson('/horizon-ui/api/jobs/unknown');

    $response->assertOk();
    expect($response->json('jobs'))->toBeEmpty();
});

it('can get batches', function () {
    $response = $this->getJson('/horizon-ui/api/batches');

    $response->assertOk()
        ->assertJsonStructure(['batches']);
});

it('defaults to auth middleware for security', function () {
    // Routes are registered using config('horizon-ui.middleware').
    // The published default includes 'auth' to protect the dashboard.
    $defaultMiddleware = require __DIR__.'/../../config/horizon-ui.php';

    expect($defaultMiddleware['middleware'])->toContain('auth');
});
