<?php

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\SupervisorCommands\ContinueWorking;
use Laravel\Horizon\SupervisorCommands\Pause;
use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

function makeSupervisor(string $name, string $status, array $queues = ['default']): stdClass
{
    $supervisor = new stdClass;
    $supervisor->name = 'app:'.$name;
    $supervisor->status = $status;
    $supervisor->options = ['connection' => 'redis', 'queue' => $queues];
    $supervisor->processes = []; // empty — resolveQueues() falls back to options.queue

    return $supervisor;
}

beforeEach(function (): void {
    // Stub the Queue facade so countPendingJobs() doesn't need real Redis
    Queue::shouldReceive('connection')->andReturnSelf()->byDefault();
    Queue::shouldReceive('size')->andReturn(0)->byDefault();
});

it('pauses idle running supervisors', function (): void {
    $supervisor = makeSupervisor('default', 'running');

    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([$supervisor]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')
        ->with($supervisor->name, Pause::class)
        ->once();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    Queue::shouldReceive('connection')->with('redis')->andReturnSelf();
    Queue::shouldReceive('size')->with('default')->andReturn(0);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});

it('resumes paused supervisors that have pending jobs', function (): void {
    $supervisor = makeSupervisor('default', 'paused');

    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([$supervisor]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')
        ->with($supervisor->name, ContinueWorking::class)
        ->once();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    Queue::shouldReceive('connection')->with('redis')->andReturnSelf();
    Queue::shouldReceive('size')->with('default')->andReturn(5);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});

it('does nothing when no supervisors are registered', function (): void {
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')->never();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});

it('does not pause an already paused supervisor', function (): void {
    $supervisor = makeSupervisor('default', 'paused');

    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([$supervisor]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')->never();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    Queue::shouldReceive('connection')->with('redis')->andReturnSelf();
    Queue::shouldReceive('size')->with('default')->andReturn(0);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});

it('does not resume a running supervisor that already has jobs', function (): void {
    $supervisor = makeSupervisor('default', 'running');

    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([$supervisor]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')->never();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    Queue::shouldReceive('connection')->with('redis')->andReturnSelf();
    Queue::shouldReceive('size')->with('default')->andReturn(3);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});

it('resolves queues from processes map when available', function (): void {
    $supervisor = makeSupervisor('default', 'running');
    $supervisor->processes = ['redis:high' => 2, 'redis:default' => 3];

    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->expects('all')->andReturn([$supervisor]);

    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->expects('push')
        ->with($supervisor->name, Pause::class)
        ->once();

    $this->app->instance(SupervisorRepository::class, $repository);
    $this->app->instance(HorizonCommandQueue::class, $commandQueue);

    // Both queues derived from processes map return 0 jobs
    Queue::shouldReceive('connection')->with('redis')->andReturnSelf();
    Queue::shouldReceive('size')->with('high')->andReturn(0);
    Queue::shouldReceive('size')->with('default')->andReturn(0);

    $this->artisan('horizon-ui:auto-pause')->assertSuccessful();
});
