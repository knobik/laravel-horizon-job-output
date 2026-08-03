<?php

namespace Knobik\HorizonJobOutput\Queue;

use Illuminate\Contracts\Queue\Job;

/**
 * Remembers the job a worker currently has in hand.
 *
 * The bus pipe is handed the command on its own, and a command only carries a
 * job of its own when it uses InteractsWithQueue. QueuedCommand — the job
 * Artisan::queue() dispatches — does not, so the worker's own events are the
 * only place its Horizon id is within reach.
 */
class CurrentJob
{
    protected ?Job $job = null;

    public function set(?Job $job): void
    {
        $this->job = $job;
    }

    public function forget(): void
    {
        $this->job = null;
    }

    /**
     * The Horizon id of the job in hand, but only when that job is the given
     * command.
     *
     * The check is what keeps a nested dispatch honest. A command handled
     * inside another job would otherwise be handed the outer job's id and
     * write its own output over it.
     */
    public function uuidFor(string $command): ?string
    {
        if (! $this->job) {
            return null;
        }

        $payload = $this->job->payload();

        return ($payload['data']['commandName'] ?? null) === $command
            ? $this->job->uuid()
            : null;
    }
}
