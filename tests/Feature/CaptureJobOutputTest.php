<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Pipes\CaptureJobOutput;
use Knobik\HorizonJobOutput\Tests\Fixtures\FakeQueueJob;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobOptingOut;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobThatFails;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobWithOutput;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobWithoutTrait;
use Knobik\HorizonJobOutput\Tests\Fixtures\RecordingStore;
use Knobik\HorizonJobOutput\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class CaptureJobOutputTest extends TestCase
{
    protected RecordingStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new RecordingStore;
        $this->app->instance(JobOutputStore::class, $this->store);
    }

    protected function pipe(): CaptureJobOutput
    {
        return $this->app->make(CaptureJobOutput::class);
    }

    protected function runJob(object $job): mixed
    {
        return $this->pipe()->handle($job, function ($job) {
            $job->handle();

            return 'done';
        });
    }

    #[Test]
    public function it_captures_output_from_a_job_using_the_trait(): void
    {
        $this->runJob(new JobWithOutput);

        $this->assertStringContainsString('working', $this->store->latest());
        $this->assertSame('job-uuid', $this->store->writes[0][0]);
    }

    /**
     * The flush lives in a finally block precisely so that a job which blew up
     * keeps whatever it printed first — which is when the output matters most.
     */
    #[Test]
    public function it_keeps_the_output_of_a_job_that_threw(): void
    {
        try {
            $this->runJob(new JobThatFails);
            $this->fail('The exception should not have been swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('deliberate failure', $e->getMessage());
        }

        $this->assertStringContainsString('about to fail', $this->store->latest());
    }

    #[Test]
    public function it_returns_whatever_the_job_returned(): void
    {
        $this->assertSame('done', $this->runJob(new JobWithOutput));
    }

    #[Test]
    public function it_ignores_jobs_that_do_not_use_the_trait(): void
    {
        $this->runJob(new JobWithoutTrait);

        $this->assertSame(0, $this->store->writeCount());
    }

    #[Test]
    public function it_respects_a_job_opting_out(): void
    {
        $this->runJob(new JobOptingOut);

        $this->assertSame(0, $this->store->writeCount());
    }

    /**
     * A synchronously dispatched job has no Horizon hash to attach output to,
     * so there is nowhere to put it.
     */
    #[Test]
    public function it_skips_jobs_that_have_no_horizon_uuid(): void
    {
        $this->runJob(new JobWithOutput(new FakeQueueJob(null)));

        $this->assertSame(0, $this->store->writeCount());
    }

    #[Test]
    public function it_captures_nothing_when_the_package_is_disabled(): void
    {
        config(['horizon-job-output.enabled' => false]);

        $this->runJob(new JobWithOutput);

        $this->assertSame(0, $this->store->writeCount());
    }

    /**
     * Regression: the pipe used to bail out before attaching an output, so a
     * job that printed anything fatalled on a null output the moment the
     * package was disabled or the job ran outside Horizon.
     */
    #[Test]
    public function a_job_still_runs_when_its_output_is_not_being_captured(): void
    {
        config(['horizon-job-output.enabled' => false]);

        $this->assertSame('done', $this->runJob(new JobWithOutput));
    }

    #[Test]
    public function a_job_without_a_horizon_uuid_still_runs(): void
    {
        $this->assertSame('done', $this->runJob(new JobWithOutput(new FakeQueueJob(null))));
    }

    #[Test]
    public function a_job_that_opted_out_still_runs(): void
    {
        $this->assertSame('done', $this->runJob(new JobOptingOut));
    }

    #[Test]
    public function it_honours_the_configured_size_limit(): void
    {
        config(['horizon-job-output.max_bytes' => 40]);

        $job = new class(new FakeQueueJob) extends JobWithOutput
        {
            public function handle(): void
            {
                for ($i = 0; $i < 20; $i++) {
                    $this->line(str_repeat('y', 30));
                }
            }
        };

        $this->runJob($job);

        $this->assertStringContainsString('output truncated', $this->store->latest());
        $this->assertLessThan(150, strlen($this->store->latest()));
    }

    /**
     * A job that writes at three levels, so that each test can say which of
     * them should have survived.
     */
    protected function chattyJob(?string $verbosity = null): object
    {
        return new class(new FakeQueueJob, $verbosity) extends JobWithOutput
        {
            // Not $verbosity: InteractsWithIO already declares one.
            public function __construct(object $job, protected ?string $level)
            {
                parent::__construct($job);
            }

            public function outputVerbosity(): ?string
            {
                return $this->level;
            }

            public function handle(): void
            {
                $this->info('always');
                $this->info('detail', 'vv');
                $this->info('debugging', 'vvv');
            }
        };
    }

    #[Test]
    public function it_writes_at_the_normal_level_by_default(): void
    {
        $this->runJob($this->chattyJob());

        $this->assertStringContainsString('always', $this->store->latest());
        $this->assertStringNotContainsString('detail', $this->store->latest());
    }

    #[Test]
    public function it_honours_the_configured_verbosity(): void
    {
        config(['horizon-job-output.verbosity' => 'vv']);

        $this->runJob($this->chattyJob());

        $this->assertStringContainsString('detail', $this->store->latest());
        $this->assertStringNotContainsString('debugging', $this->store->latest());
    }

    /**
     * Artisan treats any number of v's past three as debug, and -vvvv is what
     * people type when they want everything.
     */
    #[Test]
    public function it_accepts_more_vs_than_there_are_levels(): void
    {
        config(['horizon-job-output.verbosity' => 'vvvv']);

        $this->runJob($this->chattyJob());

        $this->assertStringContainsString('debugging', $this->store->latest());
    }

    #[Test]
    public function a_job_can_raise_its_own_verbosity(): void
    {
        config(['horizon-job-output.verbosity' => 'normal']);

        $this->runJob($this->chattyJob('vvv'));

        $this->assertStringContainsString('debugging', $this->store->latest());
    }

    #[Test]
    public function a_job_can_lower_its_own_verbosity(): void
    {
        config(['horizon-job-output.verbosity' => 'vvv']);

        $this->runJob($this->chattyJob('normal'));

        $this->assertStringNotContainsString('detail', $this->store->latest());
    }

    /**
     * A misspelled level would otherwise present as output that is simply
     * missing, with nothing tying it back to the setting.
     */
    #[Test]
    public function it_warns_and_falls_back_to_normal_on_an_unknown_level(): void
    {
        Log::shouldReceive('warning')->once();

        config(['horizon-job-output.verbosity' => 'loud']);

        $this->runJob($this->chattyJob());

        $this->assertStringContainsString('always', $this->store->latest());
        $this->assertStringNotContainsString('detail', $this->store->latest());
    }

    #[Test]
    public function the_quiet_level_records_nothing(): void
    {
        config(['horizon-job-output.verbosity' => 'quiet']);

        $this->runJob($this->chattyJob());

        $this->assertSame('', $this->store->latest());
    }

    /**
     * The point of the setting: Symfony picks the progress bar format from the
     * output's verbosity, so a higher level adds the elapsed time and estimate
     * to a job's bar exactly as it does for a command.
     */
    #[Test]
    public function a_progress_bar_reports_its_timings_at_a_higher_verbosity(): void
    {
        $job = fn () => new class(new FakeQueueJob) extends JobWithOutput
        {
            public function handle(): void
            {
                $this->withProgressBar([1, 2], fn () => null);
            }
        };

        // At the normal level the bar ends at its percentage; a higher one
        // appends the elapsed time and the estimate, each a figure and a unit.
        $timings = '/100%\s+<?\s*[\d.]+\s*(ms|secs?|mins?|hrs?|days?)/';

        $this->runJob($job());

        $this->assertDoesNotMatchRegularExpression($timings, $this->store->latest());

        config(['horizon-job-output.verbosity' => 'vv']);

        $this->runJob($job());

        $this->assertMatchesRegularExpression($timings, $this->store->latest());
    }
}
