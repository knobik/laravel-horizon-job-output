<?php

namespace Knobik\HorizonJobOutput\Tests\Concerns;

use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Pipes\CaptureJobOutput;
use Knobik\HorizonJobOutput\Tests\Fixtures\RecordingStore;

/**
 * Runs a job the way the queue does — through the bus pipe, with the handler
 * resolved out of the container — against a store that records rather than
 * needing a Redis server.
 */
trait RunsJobsThroughThePipe
{
    protected RecordingStore $store;

    /**
     * Called by Testbench through its naming convention.
     */
    protected function setUpRunsJobsThroughThePipe(): void
    {
        $this->store = new RecordingStore;

        $this->app->instance(JobOutputStore::class, $this->store);
    }

    protected function runJob(object $job): mixed
    {
        return $this->app->make(CaptureJobOutput::class)->handle($job, function ($job) {
            // Called rather than invoked directly, because the dispatcher
            // resolves a job's handle() dependencies out of the container —
            // which is how a queued Artisan command is given its kernel.
            $this->app->call([$job, 'handle']);

            return 'done';
        });
    }
}
