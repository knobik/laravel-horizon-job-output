<?php

namespace Knobik\HorizonJobOutput\Pipes;

use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\Concerns\WritesJobOutput;
use Knobik\HorizonJobOutput\Console\CapturingKernel;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Output\RedisJobOutput;
use Knobik\HorizonJobOutput\Queue\CurrentJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attaches an output instance to jobs that opt in via the WritesJobOutput trait,
 * and to the queued Artisan commands Artisan::queue() dispatches.
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
        protected Container $container,
        protected CurrentJob $current,
    ) {}

    public function handle($command, Closure $next)
    {
        if (! $this->writesOutput($command)) {
            return $next($command);
        }

        $jobId = $this->shouldCapture($command) ? $this->jobId($command) : null;

        if (! $jobId) {
            // The job still needs somewhere to write. Without this, disabling
            // the package or dispatching synchronously would make every
            // $this->info() call inside the job fatal on a null output.
            $this->attach($command, new NullOutput);

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

        $this->attach($command, $output);

        try {
            return $this->withArtisanOutput($output, fn () => $next($command));
        } finally {
            // Runs on success and on exception, so a failed job keeps whatever
            // it managed to write before blowing up.
            $output->flush(force: true);
        }
    }

    /**
     * Determine whether the given command has any output to capture.
     *
     * QueuedCommand is here because a queued Artisan command is a job whose
     * entire purpose is to produce output, and being a framework class it can
     * never use the trait. With capture_artisan off it is left alone entirely,
     * rather than given an output nothing will write to and a Redis field to
     * show for it.
     */
    protected function writesOutput($command): bool
    {
        return $this->usesTrait($command)
            || ($command instanceof QueuedCommand && $this->capturesArtisan());
    }

    protected function capturesArtisan(): bool
    {
        return (bool) $this->config->get('horizon-job-output.capture_artisan', true);
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
     * Hand the job its output, if it is the kind of job that holds one.
     *
     * A queued Artisan command has nowhere to put one: it writes through the
     * console kernel instead, which withArtisanOutput() points at the same
     * place.
     */
    protected function attach($command, OutputInterface $output): void
    {
        if ($this->usesTrait($command)) {
            $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        }
    }

    /**
     * The Horizon id of the hash this job's output belongs on.
     *
     * Jobs run synchronously have no Horizon hash to attach output to, and
     * QueuedCommand does not use InteractsWithQueue, so nothing ever sets a job
     * on it — the worker's own record of what it is running is the only route
     * to its id.
     */
    protected function jobId($command): ?string
    {
        return $command instanceof QueuedCommand
            ? $this->current->uuidFor(QueuedCommand::class)
            : $command->job?->uuid();
    }

    /**
     * Determine whether that output should actually be recorded.
     */
    protected function shouldCapture($command): bool
    {
        if (! $this->config->get('horizon-job-output.enabled', true)) {
            return false;
        }

        // The opt-out is part of the trait, so a framework job like
        // QueuedCommand has no say beyond the setting above.
        return ! method_exists($command, 'shouldCaptureOutput') || $command->shouldCaptureOutput();
    }

    /**
     * Run the job with the console kernel pointed at its output, so that any
     * Artisan command it runs is recorded along with everything else it wrote.
     *
     * The kernel is restored afterwards however the job ends: a worker handles
     * one job after another in the same process, and a stale decorator would
     * feed a finished job's output.
     */
    protected function withArtisanOutput(OutputInterface $output, Closure $run): mixed
    {
        if (! $this->capturesArtisan()) {
            return $run();
        }

        $kernel = $this->container->make(KernelContract::class);

        $this->swapKernel(new CapturingKernel($kernel, $output));

        try {
            return $run();
        } finally {
            $this->swapKernel($kernel);
        }
    }

    /**
     * Put a console kernel in the container's hands.
     *
     * The facade's cache is cleared alongside the binding. Artisan::call() is
     * how a job runs a command in practice, and a facade holds on to whatever
     * it resolved first — so rebinding on its own would reach QueuedCommand,
     * whose kernel is injected, and nothing else.
     */
    protected function swapKernel(KernelContract $kernel): void
    {
        $this->container->instance(KernelContract::class, $kernel);

        Facade::clearResolvedInstance(KernelContract::class);
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
        // The per-job hook is part of the trait, so a framework job like
        // QueuedCommand takes the configured level as it stands.
        $configured = (method_exists($command, 'outputVerbosity') ? $command->outputVerbosity() : null)
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
