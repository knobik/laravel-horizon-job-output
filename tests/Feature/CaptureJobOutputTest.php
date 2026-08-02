<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

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
}
