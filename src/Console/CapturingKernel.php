<?php

namespace Knobik\HorizonJobOutput\Console;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Knobik\HorizonJobOutput\Output\ArtisanOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wraps the console kernel so that Artisan commands run during a job write
 * into that job's output.
 *
 * The kernel writes a command's output to whatever buffer it is handed and
 * throws it away when handed nothing — which is exactly what QueuedCommand,
 * the job Artisan::queue() dispatches, does. Nothing in the kernel decides that
 * default, so it is decorated for as long as the job runs and the default
 * supplied here.
 *
 * Only call() is answered differently; everything else is the kernel's own.
 */
class CapturingKernel implements KernelContract
{
    public function __construct(
        protected KernelContract $kernel,
        protected OutputInterface $output,
    ) {}

    /**
     * A command that was given a buffer keeps it: the caller wanting the output
     * somewhere specific is the one case this must not override.
     *
     * The buffer is fresh per command so that Artisan::output() returns what
     * the command just wrote rather than everything the job has logged.
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        return $this->kernel->call($command, $parameters, $outputBuffer ?? new ArtisanOutput($this->output));
    }

    public function bootstrap()
    {
        return $this->kernel->bootstrap();
    }

    public function handle($input, $output = null)
    {
        return $this->kernel->handle($input, $output);
    }

    public function queue($command, array $parameters = [])
    {
        return $this->kernel->queue($command, $parameters);
    }

    public function all()
    {
        return $this->kernel->all();
    }

    public function output()
    {
        return $this->kernel->output();
    }

    public function terminate($input, $status)
    {
        $this->kernel->terminate($input, $status);
    }
}
