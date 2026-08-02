<?php

namespace Knobik\HorizonJobOutput\Tests;

use Knobik\HorizonJobOutput\HorizonJobOutputServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            HorizonServiceProvider::class,
            HorizonJobOutputServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', 'phpredis');

        // Horizon clones the default connection into a "horizon" one at boot,
        // applying its own prefix. A dedicated database and prefix keep the
        // suite away from anything else on the same Redis server.
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_TEST_DATABASE', 15),
        ]);

        $app['config']->set('horizon.prefix', 'horizon-job-output-tests:');
        $app['config']->set('queue.default', 'redis');
    }
}
