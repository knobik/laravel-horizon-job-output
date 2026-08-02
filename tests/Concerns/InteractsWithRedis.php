<?php

namespace Knobik\HorizonJobOutput\Tests\Concerns;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Guards the tests that need a live Redis server, and leaves nothing behind.
 */
trait InteractsWithRedis
{
    protected function redis()
    {
        return Redis::connection('horizon');
    }

    protected function skipWithoutRedis(): void
    {
        try {
            $this->redis()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'No Redis server at '.env('REDIS_HOST', '127.0.0.1').':'.env('REDIS_PORT', 6379).
                ' ('.$e->getMessage().')'
            );
        }
    }

    protected function flushRedis(): void
    {
        try {
            $this->redis()->flushdb();
        } catch (Throwable) {
            // Nothing to clean up if the server was never reachable.
        }
    }
}
