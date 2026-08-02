<?php

namespace Knobik\HorizonJobOutput;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisJobOutputStore implements JobOutputStore
{
    public function __construct(protected RedisFactory $redis) {}

    /**
     * Store the output for the given job.
     *
     * The output is written as a field on Horizon's own job hash so that it shares
     * the key's TTL. Writing to a key that does not exist would create one with no
     * expiry at all, which would leak forever, so the write is guarded.
     */
    public function put(string $jobId, string $output): bool
    {
        $connection = $this->connection();

        if (! $connection->exists($jobId)) {
            return false;
        }

        $connection->hset($jobId, self::FIELD, $output);

        return true;
    }

    public function get(string $jobId): ?string
    {
        $output = $this->connection()->hget($jobId, self::FIELD);

        return is_string($output) ? $output : null;
    }

    /**
     * Get the Redis connection Horizon stores its job hashes on.
     */
    protected function connection()
    {
        return $this->redis->connection('horizon');
    }
}
