<?php

use Laravel\Horizon\Contracts\JobRepository;
use Mockery\MockInterface;
use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

function fakeJob(array $attrs = []): object
{
    return (object) array_merge([
        'id' => 'uuid-'.uniqid(),
        'name' => 'App\\Jobs\\ProcessOrder',
        'displayName' => 'App\\Jobs\\ProcessOrder',
        'queue' => 'default',
        'status' => 'completed',
        'payload' => json_encode(['orderId' => 42]),
        'tags' => '',
        'failed_at' => null,
        'reserved_at' => null,
        'pushedAt' => 1714000000,
    ], $attrs);
}

function bindJobsMock(array $methods = []): MockInterface
{
    $jobs = Mockery::mock(JobRepository::class);

    $defaults = [
        'countRecentlyFailed' => 0,
        'countRecent' => 0,
        'countFailed' => 0,
        'countPending' => 0,
        'countCompleted' => 0,
        'deleteFailed' => null,
        'purge' => 0,
        'getJobs' => collect([]),
        'getFailed' => collect([]),
        'getPending' => collect([]),
        'getRecent' => collect([]),
        'getCompleted' => collect([]),
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

beforeEach(function (): void {
    $this->bindHorizonMocks();
});

it('returns matching jobs by class name', function (): void {
    $job = fakeJob(['displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('App\\Jobs\\SendEmail');
    expect($response->json('query'))->toBe('sendemail');
});

it('filters by queue', function (): void {
    $emailJob = fakeJob(['queue' => 'emails',  'displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);
    $defaultJob = fakeJob(['queue' => 'default', 'displayName' => 'App\\Jobs\\SendEmail', 'name' => 'App\\Jobs\\SendEmail']);

    $jobs = bindJobsMock(['getRecent' => [collect([$emailJob, $defaultJob]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail&queue=emails');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.queue'))->toBe('emails');
});

it('searches inside payload content', function (): void {
    $job = fakeJob([
        'payload' => json_encode(['recipient' => 'alice@example.com', 'subject' => 'Hello']),
    ]);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=alice');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('returns empty results for no match', function (): void {
    $job = fakeJob(['displayName' => 'App\\Jobs\\ProcessOrder', 'name' => 'App\\Jobs\\ProcessOrder']);

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=SendEmail');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
    expect($response->json('next_cursor'))->toBeNull();
});

it('respects the limit parameter', function (): void {
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

it('paginates via cursor', function (): void {
    $job = fakeJob();

    $jobs = bindJobsMock(['getRecent' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'next_cursor',
            'total_scanned',
            'total_set_size',
            'estimated_total',
            'exhausted',
            'query',
        ]);

    expect($response->json('total_scanned'))->toBe(1);
    // next_cursor is null because the second batch was empty (exhausted)
    expect($response->json('next_cursor'))->toBeNull();
    expect($response->json('exhausted'))->toBeTrue();
});

it('returns estimated_total and total_set_size when not exhausted', function (): void {
    // 50 matching jobs in a single batch; limit 25 stops before the batch ends,
    // so the search is not exhausted and the estimate reflects the full set size.
    $batch = collect(array_map(fn () => fakeJob(), range(1, 50)));

    $jobs = bindJobsMock([
        'countRecent' => 10_000,
        'getRecent' => [collect($batch->all()), collect([])],
    ]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&limit=25');

    $response->assertOk();
    expect($response->json('exhausted'))->toBeFalse();
    expect($response->json('total_set_size'))->toBe(10_000);
    // 25 matches out of 25 scanned = 100% match rate → estimate equals set size.
    expect($response->json('estimated_total'))->toBe(10_000);
});

it('reports exhausted true with exact estimated_total on the final page', function (): void {
    $job = fakeJob();

    $jobs = bindJobsMock([
        'countRecent' => 42,
        'getRecent' => [collect([$job]), collect([])],
    ]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder');

    $response->assertOk();
    expect($response->json('exhausted'))->toBeTrue();
    expect($response->json('total_set_size'))->toBe(42);
    // 1 match out of 1 scanned = 100% → estimate equals set size.
    expect($response->json('estimated_total'))->toBe(42);
});

it('reports zero estimated_total when no jobs are scanned', function (): void {
    $jobs = bindJobsMock(['getRecent' => collect([])]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder');

    $response->assertOk();
    expect($response->json('total_scanned'))->toBe(0);
    expect($response->json('estimated_total'))->toBe(0);
    expect($response->json('exhausted'))->toBeTrue();
});

it('returns a next_cursor when results fill the limit', function (): void {
    // Horizon returns 50 per page; fill one page with matching jobs so the
    // limit is reached before the batch is exhausted.
    $batch = collect(array_map(fn () => fakeJob(), range(1, 50)));

    $jobs = bindJobsMock(['getRecent' => [collect($batch->all()), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&limit=25');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(25);
    // Stopped after consuming 25 of 50 items in the batch, so cursor is 25.
    expect($response->json('next_cursor'))->toBe(25);
});

it('rejects queries shorter than 2 characters', function (): void {
    $response = $this->getJson('/horizon-ui/api/jobs/search?q=x');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

it('rejects missing query parameter', function (): void {
    $response = $this->getJson('/horizon-ui/api/jobs/search');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

it('searches failed jobs when type is failed', function (): void {
    $job = fakeJob(['status' => 'failed', 'failed_at' => '2024-04-25 10:00:00']);

    $jobs = bindJobsMock(['getFailed' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&type=failed');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('failed');
});

it('searches pending jobs when type is pending', function (): void {
    $job = fakeJob(['status' => 'pending', 'queue' => 'high']);

    $jobs = bindJobsMock(['getPending' => [collect([$job]), collect([])]]);
    $this->app->instance(JobRepository::class, $jobs);

    $response = $this->getJson('/horizon-ui/api/jobs/search?q=processorder&type=pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
