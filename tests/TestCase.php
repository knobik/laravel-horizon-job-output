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
        // The dashboard routes run through the "web" middleware group, which
        // encrypts cookies and so needs a key before any request can be made.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.redis.client', 'phpredis');

        $connection = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_TEST_DATABASE', 15),
        ];

        // The queue's own keys — including the reserved sets the reserved jobs
        // page reads — live on this connection.
        $app['config']->set('database.redis.default', $connection);

        // Horizon derives its connection by cloning the default one and applying
        // its prefix, but it does that while registering its provider, which is
        // before this callback runs. Left alone it keeps the stock config, which
        // means database 0 — a developer's own Redis data, which the suite
        // flushes between tests. Setting it here overwrites that: the connection
        // is resolved lazily, so the value that counts is the one in place at
        // first use, not at registration.
        $app['config']->set('database.redis.horizon', $connection + [
            'options' => ['prefix' => 'horizon-job-output-tests:'],
        ]);

        $app['config']->set('horizon.prefix', 'horizon-job-output-tests:');
        $app['config']->set('queue.default', 'redis');
    }
}
