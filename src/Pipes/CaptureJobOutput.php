<?php

namespace Knobik\HorizonJobOutput\Pipes;

use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Config\Repository as Config;
use Knobik\HorizonJobOutput\Concerns\WritesJobOutput;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Output\RedisJobOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Attaches an output instance to jobs that opt in via the WritesJobOutput trait.
 *
 * Registered as a global bus pipe. Queued jobs reach it through
 * CallQueuedHandler::dispatchThroughMiddleware() -> Dispatcher::dispatchNow(),
 * which pipes the command through the dispatcher's pipes. This runs inside the
 * worker with the real command instance and with $command->job already set.
 */
class CaptureJobOutput
{
    public function __construct(
        protected JobOutputStore $store,
        protected Config $config,
    ) {}

    public function handle($command, Closure $next)
    {
        if (! $this->usesTrait($command)) {
            return $next($command);
        }

        // Jobs run synchronously have no Horizon hash to attach output to.
        $jobId = $this->shouldCapture($command) ? $command->job?->uuid() : null;

        if (! $jobId) {
            // The job still needs somewhere to write. Without this, disabling
            // the package or dispatching synchronously would make every
            // $this->info() call inside the job fatal on a null output.
            $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

            return $next($command);
        }

        $output = new RedisJobOutput(
            store: $this->store,
            jobId: $jobId,
            maxBytes: (int) $this->config->get('horizon-job-output.max_bytes', 65536),
            flushIntervalMs: (int) $this->config->get('horizon-job-output.flush_interval_ms', 500),
            decorated: (bool) $this->config->get('horizon-job-output.ansi', true),
        );

        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        try {
            return $next($command);
        } finally {
            // Runs on success and on exception, so a failed job keeps whatever
            // it managed to write before blowing up.
            $output->flush(force: true);
        }
    }

    /**
     * Determine whether the given command has an output instance to be given.
     */
    protected function usesTrait($command): bool
    {
        return is_object($command)
            && in_array(WritesJobOutput::class, class_uses_recursive($command), true);
    }

    /**
     * Determine whether that output should actually be recorded.
     */
    protected function shouldCapture($command): bool
    {
        if (! $this->config->get('horizon-job-output.enabled', true)) {
            return false;
        }

        return $command->shouldCaptureOutput();
    }
}
