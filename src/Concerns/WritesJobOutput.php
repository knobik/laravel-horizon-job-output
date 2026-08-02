<?php

namespace Knobik\HorizonJobOutput\Concerns;

use Illuminate\Console\Concerns\InteractsWithIO;
use Knobik\HorizonJobOutput\Exceptions\InteractiveOutputException;

/**
 * Gives a queued job the same IO API an Artisan command has.
 *
 * The output instance is attached at execution time by the CaptureJobOutput bus
 * pipe, because unserialized jobs never run their constructor. When a job is run
 * outside the queue — calling handle() directly in a test, for instance — call
 * setOutput() first.
 */
trait WritesJobOutput
{
    use InteractsWithIO;

    /**
     * Whether this job should capture output.
     *
     * Override to disable capture for a specific job.
     */
    public function shouldCaptureOutput(): bool
    {
        return true;
    }

    /**
     * The interactive prompts inherited from InteractsWithIO cannot work on a
     * queue worker: there is no input stream to read from, so they would block
     * the worker until it timed out. Fail loudly instead.
     */
    public function confirm($question, $default = false)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }

    public function ask($question, $default = null)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }

    public function anticipate($question, $choices, $default = null)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }

    public function askWithCompletion($question, $choices, $default = null)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }

    public function secret($question, $fallback = true)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
    {
        throw InteractiveOutputException::forMethod(__FUNCTION__);
    }
}
