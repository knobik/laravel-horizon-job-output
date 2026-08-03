<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Artisan;
use Knobik\HorizonJobOutput\Queue\CurrentJob;
use Knobik\HorizonJobOutput\Tests\Concerns\RunsJobsThroughThePipe;
use Knobik\HorizonJobOutput\Tests\Fixtures\FakeQueueJob;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobWithOutput;
use Knobik\HorizonJobOutput\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;

class ArtisanOutputTest extends TestCase
{
    use RunsJobsThroughThePipe;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::command('hjo:speak', function () {
            $this->info('spoken by the command');
        });
    }

    /**
     * Stand the worker up as though it were running the given command as a job,
     * which is the only place a queued Artisan command's Horizon id exists.
     */
    protected function workerIsRunning(string $commandName, string $uuid = 'job-uuid'): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => $commandName]]);
        $job->shouldReceive('uuid')->andReturn($uuid);

        $this->app->make(CurrentJob::class)->set($job);
    }

    protected function queuedCommand(string $command = 'hjo:speak'): QueuedCommand
    {
        return new QueuedCommand([$command]);
    }

    #[Test]
    public function it_captures_the_output_of_a_queued_artisan_command(): void
    {
        $this->workerIsRunning(QueuedCommand::class);

        $this->runJob($this->queuedCommand());

        $this->assertStringContainsString('spoken by the command', $this->store->latest());
        $this->assertSame('job-uuid', $this->store->writes[0][0]);
    }

    /**
     * QueuedCommand carries no job of its own, so the id has to come from the
     * worker. Without one there is no hash to write to.
     */
    #[Test]
    public function it_captures_nothing_when_no_job_is_in_hand(): void
    {
        $this->runJob($this->queuedCommand());

        $this->assertSame(0, $this->store->writeCount());
    }

    /**
     * A command dispatched from inside another job must not be handed that
     * job's id, or it would write its output straight over it.
     */
    #[Test]
    public function it_refuses_the_id_of_a_job_that_is_running_something_else(): void
    {
        $this->workerIsRunning(JobWithOutput::class);

        $this->runJob($this->queuedCommand());

        $this->assertSame(0, $this->store->writeCount());
    }

    #[Test]
    public function it_captures_nothing_when_the_package_is_disabled(): void
    {
        config(['horizon-job-output.enabled' => false]);

        $this->workerIsRunning(QueuedCommand::class);

        $this->runJob($this->queuedCommand());

        $this->assertSame(0, $this->store->writeCount());
    }

    /**
     * A queued command that does not exist must still fail as a job failure
     * rather than as something this package did.
     */
    #[Test]
    public function a_queued_command_that_does_not_exist_still_fails_normally(): void
    {
        $this->workerIsRunning(QueuedCommand::class);

        $this->expectException(CommandNotFoundException::class);

        $this->runJob($this->queuedCommand('hjo:no-such-command'));
    }

    #[Test]
    public function it_captures_a_command_run_from_inside_a_job(): void
    {
        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public function handle(): void
            {
                $this->info('the job speaks');

                Artisan::call('hjo:speak');
            }
        };

        $this->runJob($job);

        $this->assertStringContainsString('the job speaks', $this->store->latest());
        $this->assertStringContainsString('spoken by the command', $this->store->latest());
    }

    /**
     * Substituting the buffer must not take the output away from the job that
     * ran the command: Artisan::output() only answers if the buffer it wrote to
     * can hand the text back.
     */
    #[Test]
    public function a_job_can_still_read_the_output_of_the_command_it_ran(): void
    {
        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public string $seen = '';

            public function handle(): void
            {
                Artisan::call('hjo:speak');

                $this->seen = Artisan::output();
            }
        };

        $this->runJob($job);

        $this->assertStringContainsString('spoken by the command', $job->seen);
    }

    /**
     * A caller that asked for its output somewhere specific keeps it there.
     */
    #[Test]
    public function it_leaves_a_command_given_its_own_buffer_alone(): void
    {
        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public ?BufferedOutput $buffer = null;

            public function handle(): void
            {
                Artisan::call('hjo:speak', [], $this->buffer = new BufferedOutput);
            }
        };

        $this->runJob($job);

        $this->assertStringContainsString('spoken by the command', $job->buffer->fetch());
        $this->assertStringNotContainsString('spoken by the command', (string) $this->store->latest());
    }

    /**
     * A queued command is left alone entirely when the setting is off, rather
     * than given an output nothing writes to and an empty Redis field with it.
     */
    #[Test]
    public function it_can_be_told_to_leave_queued_commands_alone(): void
    {
        config(['horizon-job-output.capture_artisan' => false]);

        $this->workerIsRunning(QueuedCommand::class);

        $this->runJob($this->queuedCommand());

        $this->assertSame(0, $this->store->writeCount());
    }

    #[Test]
    public function it_can_be_told_to_leave_commands_run_inside_a_job_alone(): void
    {
        config(['horizon-job-output.capture_artisan' => false]);

        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public function handle(): void
            {
                $this->info('the job speaks');

                Artisan::call('hjo:speak');
            }
        };

        $this->runJob($job);

        // The job's own output carries on as it always did.
        $this->assertStringContainsString('the job speaks', $this->store->latest());
        $this->assertStringNotContainsString('spoken by the command', $this->store->latest());
    }

    #[Test]
    public function it_puts_the_console_kernel_back_when_the_job_is_done(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        $this->runJob(new JobWithOutput);

        $this->assertSame($kernel, $this->app->make(KernelContract::class));
    }

    /**
     * A worker handles one job after another in the same process, so a kernel
     * left decorated by a job that blew up would feed a finished job's output.
     */
    #[Test]
    public function it_puts_the_console_kernel_back_when_the_job_throws(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public function handle(): void
            {
                throw new RuntimeException('deliberate failure');
            }
        };

        try {
            $this->runJob($job);
        } catch (RuntimeException) {
            // The point of the test is what happens on the way out.
        }

        $this->assertSame($kernel, $this->app->make(KernelContract::class));
    }
}
