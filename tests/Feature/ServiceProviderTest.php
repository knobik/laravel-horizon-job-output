<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Knobik\HorizonJobOutput\HorizonJobOutputServiceProvider;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Pipes\CaptureJobOutput;
use Knobik\HorizonJobOutput\Queue\CurrentJob;
use Knobik\HorizonJobOutput\Tests\TestCase;
use Laravel\Horizon\Contracts\JobRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

class ServiceProviderTest extends TestCase
{
    protected function pipes(): array
    {
        $dispatcher = $this->app->make(BusDispatcherContract::class);

        return (array) (new ReflectionProperty($dispatcher, 'pipes'))->getValue($dispatcher);
    }

    protected function setPipes(array $pipes): void
    {
        $this->app->make(BusDispatcherContract::class)->pipeThrough($pipes);
    }

    protected function reregisterPipe(): void
    {
        $provider = $this->app->getProvider(HorizonJobOutputServiceProvider::class);

        (new ReflectionMethod($provider, 'registerBusPipe'))->invoke($provider);
    }

    protected function reregisterKeys(): void
    {
        $provider = $this->app->getProvider(HorizonJobOutputServiceProvider::class);

        (new ReflectionMethod($provider, 'exposeOutputOnJobRepository'))->invoke($provider, $this->app->make(JobRepository::class));
    }

    #[Test]
    public function it_adds_the_output_field_to_the_repository_whitelist(): void
    {
        $repository = $this->app->make(JobRepository::class);

        // The repository reads a fixed whitelist with HMGET rather than HGETALL,
        // so without this the field is stored but never reaches the dashboard.
        $this->assertContains(JobOutputStore::FIELD, $repository->keys);
    }

    #[Test]
    public function it_does_not_add_the_output_field_twice(): void
    {
        $this->reregisterKeys();
        $this->reregisterKeys();

        $keys = $this->app->make(JobRepository::class)->keys;

        $this->assertSame(1, count(array_keys($keys, JobOutputStore::FIELD, true)));
    }

    #[Test]
    public function it_registers_the_capture_pipe(): void
    {
        $this->assertContains(CaptureJobOutput::class, $this->pipes());
    }

    /**
     * Dispatcher::pipeThrough() replaces the pipe list outright and offers no
     * getter, so a naive registration would silently delete pipes the host
     * application registered — breaking their jobs, not ours.
     */
    #[Test]
    public function it_preserves_bus_pipes_the_application_already_registered(): void
    {
        $this->setPipes(['App\Pipes\First', 'App\Pipes\Second']);

        $this->reregisterPipe();

        $pipes = $this->pipes();

        $this->assertContains('App\Pipes\First', $pipes);
        $this->assertContains('App\Pipes\Second', $pipes);
        $this->assertContains(CaptureJobOutput::class, $pipes);
    }

    #[Test]
    public function it_does_not_register_the_pipe_twice(): void
    {
        $this->reregisterPipe();
        $this->reregisterPipe();

        $this->assertSame(1, count(array_keys($this->pipes(), CaptureJobOutput::class, true)));
    }

    #[Test]
    public function it_keeps_horizons_own_views_reachable_after_taking_over_the_layout(): void
    {
        $hints = $this->app['view']->getFinder()->getHints();

        $this->assertArrayHasKey('horizon-original', $hints);
        $this->assertNotEmpty($hints['horizon-original']);

        // The override has to win, or the panel is never injected.
        $this->assertStringContainsString(
            'laravel-horizon-job-output',
            $hints['horizon'][0],
            'The package view path must take precedence over Horizon\'s.'
        );
    }

    /**
     * The worker's events are the only place a queued Artisan command's Horizon
     * id can be found, so this wiring is what makes that capture work at all —
     * everything downstream of it can pass with the listeners never registered.
     */
    #[Test]
    public function it_tracks_the_job_the_worker_is_processing(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => QueuedCommand::class]]);
        $job->shouldReceive('uuid')->andReturn('job-uuid');

        $current = $this->app->make(CurrentJob::class);

        event(new JobProcessing('redis', $job));

        $this->assertSame('job-uuid', $current->uuidFor(QueuedCommand::class));

        event(new JobProcessed('redis', $job));

        $this->assertNull($current->uuidFor(QueuedCommand::class));
    }

    #[Test]
    public function the_bus_dispatcher_is_the_concrete_implementation_the_merge_relies_on(): void
    {
        // The reflection-based merge only works against Illuminate's dispatcher.
        // If this ever stops holding, registerBusPipe() bails out instead.
        $this->assertInstanceOf(BusDispatcher::class, $this->app->make(BusDispatcherContract::class));
    }
}
