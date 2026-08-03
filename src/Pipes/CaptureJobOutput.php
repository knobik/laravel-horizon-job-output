<?php

namespace Knobik\HorizonJobOutput\Pipes;

use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\Concerns\WritesJobOutput;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Output\RedisJobOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
    /**
     * The verbosity levels a job can be configured to write at.
     *
     * Keyed by Artisan's own flag names, which is the vocabulary
     * InteractsWithIO already accepts on every write helper, and by Symfony's
     * names for the same levels, which is what a config file invites people to
     * write.
     */
    protected const VERBOSITY = [
        'quiet' => OutputInterface::VERBOSITY_QUIET,
        'normal' => OutputInterface::VERBOSITY_NORMAL,
        'v' => OutputInterface::VERBOSITY_VERBOSE,
        'vv' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        'vvv' => OutputInterface::VERBOSITY_DEBUG,
        'verbose' => OutputInterface::VERBOSITY_VERBOSE,
        'very_verbose' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        'debug' => OutputInterface::VERBOSITY_DEBUG,
    ];

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
            verbosity: $this->verbosity($command),
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

    /**
     * Work out the level the job should write at.
     *
     * Symfony's ProgressBar picks its format from the output's verbosity
     * whenever no format was set explicitly, and withProgressBar() never sets
     * one, so this is what gives a job's progress bar the elapsed time,
     * estimate and memory figures that -v, -vv and -vvv give a command.
     */
    protected function verbosity($command): int
    {
        $configured = $command->outputVerbosity()
            ?? $this->config->get('horizon-job-output.verbosity', 'normal');

        $level = $this->levelFor((string) $configured);

        if ($level === null) {
            // Left silent, a typo here would present as output that is simply
            // missing, with nothing to connect it back to the setting.
            Log::warning(
                "[horizon-job-output] '{$configured}' is not a verbosity level, so jobs will write at ".
                'the normal level. Expected one of: '.implode(', ', array_keys(self::VERBOSITY)).'.'
            );

            return OutputInterface::VERBOSITY_NORMAL;
        }

        return $level;
    }

    /**
     * Resolve a level name, or null if there is no such level.
     */
    protected function levelFor(string $name): ?int
    {
        $name = strtolower(trim($name));

        // Artisan treats any number of v's beyond three as debug, and -vvvv is
        // what people reach for when they want everything.
        if (preg_match('/^v{4,}$/', $name)) {
            $name = 'vvv';
        }

        return self::VERBOSITY[$name] ?? null;
    }
}
