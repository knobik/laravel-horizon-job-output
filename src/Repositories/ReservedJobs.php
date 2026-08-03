<?php

namespace Knobik\HorizonJobOutput\Repositories;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\JobOutputStore;
use Laravel\Horizon\Contracts\SupervisorRepository;
use ReflectionMethod;
use Throwable;

/**
 * Lists the jobs the workers currently hold.
 *
 * Horizon has no view of this. Reserved jobs sit in the pending list with
 * nothing to set them apart, and a job whose worker died stays reserved —
 * invisible — until its reservation expires and it is migrated back.
 *
 * The source is the queue's own `{queue}:reserved` sorted set rather than
 * Horizon's `pending_jobs`. That set holds exactly the in-flight jobs, so it is
 * bounded by the worker count instead of the backlog, and its score is the
 * reservation deadline, which is the only way to tell a running job from an
 * abandoned one. Scanning `pending_jobs` for `status = reserved` would be O(the
 * whole backlog), and its scores are negative timestamps, so the reserved jobs
 * sort to the very tail — behind every long-delayed job, which never leaves.
 */
class ReservedJobs
{
    public function __construct(
        protected Application $app,
        protected QueueFactory $queue,
        protected RedisFactory $redis,
        protected SupervisorRepository $supervisors,
    ) {}

    /**
     * Get every reserved job, most recently reserved first.
     */
    public function all(): Collection
    {
        // One reading of the clock for the whole response, so every elapsed time
        // on the page is measured against the same instant.
        $now = microtime(true);

        $reserved = $this->queues()
            ->groupBy('connection')
            ->flatMap(fn (Collection $queues, string $connection) => $this->reservedOn(
                $connection, $queues->pluck('queue')->all(), $now
            ));

        // Reservations are granted for a fixed period, so the furthest deadline
        // is the newest one.
        return $this->enrich($reserved, $now)->sortByDesc('expires_in')->values();
    }

    /**
     * Read the reserved sets of every queue on one connection.
     *
     * Laravel 13.4 added RedisQueue::reservedJobs(), which reads these same sets
     * and confirms they are fair game, but it discards the score — and the score
     * is the reservation deadline this page is built around. It also does not
     * exist on Laravel 12, which the package still supports. Reading the sets
     * directly covers both versions with one code path.
     */
    protected function reservedOn(string $connection, array $queues, float $now): Collection
    {
        $redisQueue = $this->queue->connection($connection);

        if (! $redisQueue instanceof RedisQueue) {
            return collect();
        }

        $keys = array_map(fn (string $queue) => $this->reservedKey($redisQueue, $queue), $queues);

        try {
            $sets = $this->pipeline($redisQueue->getConnection(), function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    // Both phpredis and Predis return the set as member => score
                    // with this option, so each payload arrives as a key.
                    $pipe->zrange($key, 0, -1, ['withscores' => true]);
                }
            });
        } catch (Throwable $e) {
            // A connection that is misconfigured or unreachable should leave the
            // page listing the queues that do work, not fail outright. It should
            // not do so silently, though: an empty page and a broken connection
            // look identical from the dashboard.
            Log::warning(
                "[horizon-job-output] Could not read the reserved jobs on the '{$connection}' queue connection: ".
                $e->getMessage()
            );

            return collect();
        }

        return collect($sets)->flatMap(fn ($set, int $index) => collect($set)->map(
            fn ($expiresAt, $payload) => $this->parse($payload, (float) $expiresAt, $connection, $queues[$index], $now)
        ))->filter()->values();
    }

    /**
     * Build the key of one queue's reserved set.
     *
     * Laravel 13 moved the key building behind a protected getQueueRedisKey()
     * that wraps the queue in a hash tag on cluster connections; on Laravel 12,
     * which this package still supports, the public getQueue() is the whole of
     * it. Chosen by feature detection rather than by catching the failure —
     * reaching for a method that is not there would otherwise surface as a queue
     * with no reserved jobs, which is indistinguishable from a quiet queue.
     */
    protected function reservedKey(RedisQueue $queue, string $name): string
    {
        $key = method_exists($queue, 'getQueueRedisKey')
            ? (new ReflectionMethod($queue, 'getQueueRedisKey'))->invoke($queue, $name)
            : $queue->getQueue($name);

        return $key.':reserved';
    }

    /**
     * Turn a raw reserved payload into a row.
     */
    protected function parse(string $payload, float $expiresAt, string $connection, string $queue, float $now): ?array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Horizon keys its job hashes on the same id, preferring the uuid.
        $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return [
            'id' => $id,
            'connection' => $connection,
            'queue' => $queue,
            'name' => $decoded['displayName'] ?? null,
            'attempts' => (int) ($decoded['attempts'] ?? 0),
            'tags' => array_values(array_filter((array) ($decoded['tags'] ?? []), 'is_string')),
            'running_for' => null,
            'expires_in' => $expiresAt - $now,
            // The worker renews nothing, so a deadline in the past means the
            // process holding this job is gone and Horizon has not yet migrated
            // the job back onto the queue.
            'expired' => $expiresAt < $now,
            'has_output' => false,
        ];
    }

    /**
     * Add what only Horizon's job hash knows: when the job actually started, and
     * whether it has written any output worth clicking through for.
     *
     * Everything else on the row already came out of the payload the reserved
     * set stores, which is the same payload Horizon built its hash from.
     *
     * Only these two fields are read, rather than going through
     * JobRepository::getJobs(). That reads a fixed whitelist which includes the
     * full payload and the output field this package appends — up to `max_bytes`
     * per job, pulled across the wire on every poll, to answer a boolean.
     */
    protected function enrich(Collection $reserved, float $now): Collection
    {
        if ($reserved->isEmpty()) {
            return $reserved;
        }

        try {
            $connection = $this->redis->connection('horizon');

            $records = $this->pipeline($connection, function ($pipe) use ($reserved) {
                foreach ($reserved as $job) {
                    $pipe->hget($job['id'], 'reserved_at');
                    $pipe->hstrlen($job['id'], JobOutputStore::FIELD);
                }
            });
        } catch (Throwable) {
            return $reserved;
        }

        return $reserved->values()->map(function (array $job, int $index) use ($records, $now) {
            [$reservedAt, $outputLength] = [$records[$index * 2] ?? null, $records[$index * 2 + 1] ?? 0];

            return array_merge($job, [
                // Absent when Horizon has already trimmed the job; the row still
                // stands on what the payload carries.
                'running_for' => is_numeric($reservedAt) ? $now - (float) $reservedAt : null,
                'has_output' => (int) $outputLength > 0,
            ]);
        });
    }

    /**
     * Run commands in one round trip.
     *
     * Mirrors Horizon's own UsesClusterAwarePipeline, which cannot be used here
     * because it pipelines a single fixed connection while this reads both the
     * queue's and Horizon's.
     */
    protected function pipeline(Connection $connection, callable $callback): array
    {
        return $connection instanceof PhpRedisClusterConnection
            ? $connection->transaction($callback)
            : $connection->pipeline($callback);
    }

    /**
     * Work out which queues to look at.
     *
     * Laravel finds queues with `KEYS queues:*`, which blocks Redis for as long
     * as it takes to walk the keyspace — not something to run behind a page that
     * polls every couple of seconds. The configured supervisors cover the normal
     * case; the running ones are read too because the two disagree whenever the
     * config has changed since the last deploy, and the workers still draining a
     * queue that has since been removed are exactly the ones worth seeing.
     */
    protected function queues(): Collection
    {
        return $this->configuredQueues()
            ->merge($this->runningQueues())
            ->unique(fn (array $queue) => $queue['connection'].':'.$queue['queue'])
            ->values();
    }

    protected function runningQueues(): Collection
    {
        try {
            $supervisors = $this->supervisors->all();
        } catch (Throwable) {
            return collect();
        }

        // Each supervisor records its process counts keyed by "connection:queue",
        // where the queue half may be a comma-separated list.
        return collect($supervisors)
            ->flatMap(fn ($supervisor) => array_keys((array) ($supervisor->processes ?? [])))
            ->flatMap(function (string $key) {
                [$connection, $queues] = array_pad(explode(':', $key, 2), 2, null);

                return $this->pairs($connection, $queues);
            });
    }

    protected function configuredQueues(): Collection
    {
        // Horizon layers each environment over the defaults, and only the
        // defaults normally carry the connection and queue names.
        $supervisors = array_replace_recursive(
            (array) config('horizon.defaults', []),
            (array) config('horizon.environments.'.$this->app->environment(), []),
        );

        return collect($supervisors)->flatMap(
            fn ($supervisor) => $this->pairs($supervisor['connection'] ?? null, $supervisor['queue'] ?? null)
        );
    }

    /**
     * Expand a connection and its queues into one entry per queue.
     */
    protected function pairs(?string $connection, string|array|null $queues): Collection
    {
        if (! $connection || $queues === null) {
            return collect();
        }

        return collect(is_array($queues) ? $queues : explode(',', $queues))
            ->map(fn ($queue) => trim((string) $queue))
            ->filter()
            ->map(fn (string $queue) => ['connection' => $connection, 'queue' => $queue]);
    }
}
