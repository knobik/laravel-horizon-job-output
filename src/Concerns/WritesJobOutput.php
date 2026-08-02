<?php

namespace Knobik\HorizonJobOutput\Concerns;

use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Console\OutputStyle;
use Knobik\HorizonJobOutput\Exceptions\InteractiveOutputException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Gives a queued job the same IO API an Artisan command has.
 *
 * The output instance is attached at execution time by the CaptureJobOutput bus
 * pipe, because unserialized jobs never run their constructor. Writing output is
 * never a reason for a job to fail, so if no output was attached — the job was
 * constructed and run by hand, or the package is switched off — writes fall back
 * to discarding rather than erroring.
 */
trait WritesJobOutput
{
    /*
     * Every write path in InteractsWithIO reaches $this->output directly. The
     * five methods below are the only ones that touch it; the rest of the API
     * (info, comment, error, alert, …) delegates through them. They are aliased
     * here so the overrides further down can guarantee an output exists and
     * then hand over to the original implementation.
     */
    use InteractsWithIO {
        line as protected writeLineWithOutput;
        newLine as protected writeNewLineWithOutput;
        table as protected writeTableWithOutput;
        warn as protected writeWarningWithOutput;
        withProgressBar as protected runProgressBarWithOutput;
    }

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
     * Make sure there is somewhere to write.
     *
     * A job that prints must not depend on how it was started. Nothing attaches
     * an output when a job is constructed and invoked directly, so one that
     * discards is supplied instead of letting the write fail.
     */
    protected function ensureOutput(): void
    {
        if (! isset($this->output)) {
            $this->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        }
    }

    public function getOutput()
    {
        $this->ensureOutput();

        return $this->output;
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->ensureOutput();

        $this->writeLineWithOutput($string, $style, $verbosity);
    }

    public function newLine($count = 1)
    {
        $this->ensureOutput();

        return $this->writeNewLineWithOutput($count);
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $this->ensureOutput();

        $this->writeTableWithOutput($headers, $rows, $tableStyle, $columnStyles);
    }

    public function warn($string, $verbosity = null)
    {
        $this->ensureOutput();

        $this->writeWarningWithOutput($string, $verbosity);
    }

    public function withProgressBar($totalSteps, \Closure $callback)
    {
        $this->ensureOutput();

        return $this->runProgressBarWithOutput($totalSteps, $callback);
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
