<?php

use Laravel\Horizon\Contracts\JobRepository;
use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

function fakeJob(array $attrs = []): object
{
    return (object) array_merge([
        'id'          => 'uuid-'.uniqid(),
        'name'        => 'App\\Jobs\\ProcessOrder',
        'displayName' => 'App\\Jobs\\ProcessOrder',
        'queue'       => 'default',
        'status'      => 'completed',
        'payload'     => json_encode(['orderId' => 42]),
        'tags'        => '',
        'failed_at'   => null,
        'reserved_at' => null,
        'pushedAt'    => 1714000000,
    ], $attrs);
}

function bindJobsMock(array $methods = []): \Mockery\MockInterface
{
    $jobs = Mockery::mock(JobRepository::class);

    $defaults = [
        'countRecentlyFailed' => 0,
        'countRecent'         => 0,
        'countFailed'         => 0,
        'countCompleted'      => 0,
        'deleteFailed'        => null,
        'purge'               => 0,
        'getJobs'             => collect([]),
        'getFailed'           => collect([]),
        'getPending'          => collect([]),
        'getRecent'           => collect([]),
    ];

    foreach ($defaults as $method => $return) {
        if (! array_key_exists($method, $methods)) {
            $jobs->allows($method)->andReturn($return);
        }
    }

    foreach ($methods as $method => $returns) {
        if (is_array($returns)) {
            $jobs->allows($method)->andReturn(...$returns);
        } else {
            $jobs->allows($method)->andReturn($returns);
        }
    }

    return $jobs;
}

beforeEach(function () {
    $this->bindHorizonMocks();
});

it('returns matching jobs by class name', function () {
    $job = fakeJob(['displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('App\\Jobs\\SendEmail');
    expect($response->json('query'))->toBe('sendemail');
});

it('filters by queue', function () {
    $emailJob   = fakeJob(['queue' => 'emails',  'displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);
    $defaultJob = fakeJob(['queue' => 'default', 'displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);

    $jobs = bindJobsMock(['getRecent' => [collect([$emailJob, $defaultJob]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail&queue=emails');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.queue'))->toBe('emails');
});

it('searches inside payload content', function () {
    $job = fakeJob([
        'payload' => json_encode(['recipient' => 'alice@example.com', 'subject' => 'Hello']),
    ]);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=alice');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('returns empty results for no match', function () {
    $job = fakeJob(['displayName' => 'App\\Jobs\\ProcessOrder', 'name' => 'App\\Jobs\\ProcessOrder']);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
    expect($response->json('next_cursor'))->toBeNull();
});

it('respects the limit parameter', function () {
    $jobs = bindJobsMock(['getRecent' => [
        collect([
            fakeJob(['displayName' => 'App\\Jobs\\ProcessOrder', 'name' => 'App\\Jobs\\ProcessOrder']),
            fakeJob(['displayName' => 'App\\Jobs\\ProcessOrder', 'name' => 'App\\Jobs\\ProcessOrder']),
            fakeJob(['displayName' => 'App\\Jobs\\ProcessOrder', 'name' => 'App\\Jobs\\ProcessOrder']),
        ]),
        collect([]),
    ]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&limit=2');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('paginates via cursor', function () {
    $job = fakeJob();

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'total_scanned', 'query']);

    expect($response->json('total_scanned'))->toBe(1);
    // next_cursor is null because the second batch was empty (exhausted)
    expect($response->json('next_cursor'))->toBeNull();
});

it('returns a next_cursor when results fill the limit', function () {
    $batch = collect(array_map(fn () => fakeJob(), range(1, 200)));

    $jobs = bindJobsMock(['getRecent' => [collect($batch->all()), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&limit=25');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(25);
    expect($response->json('next_cursor'))->toBe(200);
});

it('rejects queries shorter than 2 characters', function () {
    $response = $this->getJson('/horizon-ui/api/jobs/search?q=x');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

it('rejects missing query parameter', function () {
    $response = $this->getJson('/horizon-ui/api/jobs/search');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

it('searches failed jobs when type is failed', function () {
    $job = fakeJob(['status' => 'failed', 'failed_at' => '2024-04-25 10:00:00']);

    $jobs = bindJobsMock(['getFailed' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&type=failed');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('failed');
});

it('searches pending jobs when type is pending', function () {
    $job = fakeJob(['status' => 'pending', 'queue' => 'high']);

    $jobs = bindJobsMock(['getPending' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&type=pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
