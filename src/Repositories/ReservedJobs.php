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
 *
 * It also owns the page's one write, release(), which puts a reservation back
 * onto its queue. That lives here rather than in a service of its own because
 * it needs three things this class already has and nothing else does: the
 * version-detected key building, the queue list to validate against, and the
 * connection resolution.
 */
class ReservedJobs
{
    /**
     * Move one job out of the reserved set and back onto its queue.
     *
     * KEYS[1] the reserved set, KEYS[2] the queue, KEYS[3] the notify list.
     * ARGV[1] the exact reserved payload.
     *
     * The zrem result is what makes this safe to fire from a page that is up to
     * one poll interval out of date. In that window the worker may have finished
     * the job and deleted its reservation, or another worker's pop() may have
     * migrated it already; pushing unconditionally would put a job that has
     * already run back onto the queue.
     *
     * The rest mirrors LuaScripts::migrateExpiredJobs, notify list included.
     * That list is what wakes a worker blocked in blpop when block_for is set,
     * and the matching lpop in LuaScripts::pop only runs when a job was really
     * popped, so pushing one entry per job keeps the two balanced.
     */
    protected const RELEASE_SCRIPT = <<<'LUA'
    if redis.call('zrem', KEYS[1], ARGV[1]) == 0 then
        return 0
    end

    redis.call('rpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)

    return 1
    LUA;

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
     * Put one reserved job back onto its queue.
     *
     * This is exactly what Laravel does with a reservation that has run out —
     * see LuaScripts::migrateExpiredJobs — brought forward to now. That
     * migration only ever runs inside RedisQueue::pop(), so a queue whose
     * workers have all died never reaches it: the job stays reserved, and the
     * page lists it as expired, until a worker comes back to that queue. This is
     * the way out of that.
     *
     * Returns true only when this call was the one that moved the job.
     */
    public function release(string $connection, string $queue, string $id): bool
    {
        // The Redis keys are built out of these two values, so they are checked
        // against the same configured-plus-running list the page is drawn from.
        // The endpoint can then only act on a queue it would have listed.
        if (! $this->lists($connection, $queue)) {
            return false;
        }

        $redisQueue = $this->redisQueue($connection);

        if ($redisQueue === null) {
            return false;
        }

        $key = $this->queueKey($redisQueue, $queue);
        $reserved = $this->reservedKey($redisQueue, $queue);

        try {
            $redis = $redisQueue->getConnection();

            $payload = $this->findReserved($redis, $reserved, $id);

            if ($payload === null) {
                return false;
            }

            return (int) $redis->eval(
                self::RELEASE_SCRIPT, 3, $reserved, $key, $key.':notify', $payload
            ) === 1;
        } catch (Throwable $e) {
            Log::warning(
                "[horizon-job-output] Could not release the reservation on job '{$id}' on the ".
                "'{$connection}' queue connection: ".$e->getMessage()
            );

            return false;
        }
    }

    /**
     * Find the exact reserved payload a job id belongs to.
     *
     * The reserved set is keyed by the payload, not the id, so the member has to
     * be matched by hand — and it is the member itself that the release script
     * needs, since anything reassembled from the decoded job would be a
     * different string and would not remove. The scan is bounded by the worker
     * count, which is the same cost as one poll of the page.
     */
    protected function findReserved(Connection $redis, string $reservedKey, string $id): ?string
    {
        foreach ((array) $redis->zrange($reservedKey, 0, -1) as $payload) {
            $decoded = json_decode((string) $payload, true);

            if (is_array($decoded) && $this->idOf($decoded) === $id) {
                return (string) $payload;
            }
        }

        return null;
    }

    /**
     * Whether a connection and queue are among the ones this page reports on.
     *
     * The configured list is asked first because it is a plain config read,
     * while the running one goes to Redis for the supervisors. Ordered the other
     * way round, every release would pay for that lookup to confirm a queue the
     * config already named.
     */
    protected function lists(string $connection, string $queue): bool
    {
        $matches = fn (array $pair) => $pair['connection'] === $connection && $pair['queue'] === $queue;

        return $this->configuredQueues()->contains($matches)
            || $this->runningQueues()->contains($matches);
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
        $redisQueue = $this->redisQueue($connection);

        if ($redisQueue === null) {
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
     * Resolve a queue connection, if it is one there is anything to read on.
     *
     * A reserved set is a Redis queue's own structure, so a Horizon supervisor
     * pointed at any other driver has nothing here either to list or to release.
     * One definition of that, shared by both, rather than the listing and the
     * release each deciding what they can work with.
     */
    protected function redisQueue(string $connection): ?RedisQueue
    {
        $queue = $this->queue->connection($connection);

        return $queue instanceof RedisQueue ? $queue : null;
    }

    /**
     * Build the Redis key one queue's lists and sets hang off.
     *
     * Laravel 13 moved the key building behind a protected getQueueRedisKey()
     * that wraps the queue in a hash tag on cluster connections; on Laravel 12,
     * which this package still supports, the public getQueue() is the whole of
     * it. Chosen by feature detection rather than by catching the failure —
     * reaching for a method that is not there would otherwise surface as a queue
     * with no reserved jobs, which is indistinguishable from a quiet queue.
     *
     * Every key release() touches is derived from this one string, which is what
     * keeps its three keys inside a single hash slot on a cluster.
     */
    protected function queueKey(RedisQueue $queue, string $name): string
    {
        return method_exists($queue, 'getQueueRedisKey')
            ? (new ReflectionMethod($queue, 'getQueueRedisKey'))->invoke($queue, $name)
            : $queue->getQueue($name);
    }

    /**
     * Build the key of one queue's reserved set.
     */
    protected function reservedKey(RedisQueue $queue, string $name): string
    {
        return $this->queueKey($queue, $name).':reserved';
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

        $id = $this->idOf($decoded);

        if ($id === null) {
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
     * The id a decoded payload is keyed on.
     *
     * Horizon keys its job hashes on the same id, preferring the uuid. This is
     * the one rule the listing and the release have to agree on — the id put on
     * a row is the id matched against the reserved set when that row's button is
     * clicked — so both go through here rather than restating it.
     */
    protected function idOf(array $decoded): ?string
    {
        $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
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
