<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Tests\Concerns\InteractsWithRedis;
use Knobik\HorizonJobOutput\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class JobOutputStoreTest extends TestCase
{
    use InteractsWithRedis;

    protected JobOutputStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutRedis();
        $this->flushRedis();

        $this->store = $this->app->make(JobOutputStore::class);
    }

    protected function tearDown(): void
    {
        $this->flushRedis();

        parent::tearDown();
    }

    /**
     * The whole cleanup story rests on output living inside Horizon's job hash.
     * Writing to a key that no longer exists would create a fresh one with no
     * expiry, which nothing would ever clean up.
     */
    #[Test]
    public function it_refuses_to_write_when_the_job_hash_does_not_exist(): void
    {
        $result = $this->store->put('missing-job', 'output that has nowhere to go');

        $this->assertFalse($result);
        $this->assertSame(0, (int) $this->redis()->exists('missing-job'));
    }

    #[Test]
    public function it_never_leaves_a_key_without_an_expiry(): void
    {
        $this->store->put('missing-job', 'output');

        // -2 is "no such key". -1 would be a key that lives forever, which is
        // the failure this guard exists to prevent.
        $this->assertSame(-2, (int) $this->redis()->ttl('missing-job'));
    }

    #[Test]
    public function it_writes_into_the_existing_job_hash(): void
    {
        $this->redis()->hset('job-1', 'status', 'reserved');

        $this->assertTrue($this->store->put('job-1', "line one\nline two"));
        $this->assertSame("line one\nline two", $this->store->get('job-1'));

        // The job's own fields must survive alongside the output.
        $this->assertSame('reserved', $this->redis()->hget('job-1', 'status'));
    }

    #[Test]
    public function it_preserves_the_ttl_horizon_set_on_the_job(): void
    {
        $this->redis()->hset('job-2', 'status', 'reserved');
        $this->redis()->expire('job-2', 3600);

        $before = (int) $this->redis()->ttl('job-2');

        $this->store->put('job-2', 'first write');
        $this->store->put('job-2', 'first write plus more');

        $after = (int) $this->redis()->ttl('job-2');

        $this->assertGreaterThan(0, $after);
        $this->assertLessThanOrEqual($before, $after);
        $this->assertGreaterThan($before - 5, $after, 'Writing output must not extend or reset the job TTL.');
    }

    #[Test]
    public function it_returns_null_when_a_job_has_no_output(): void
    {
        $this->redis()->hset('job-3', 'status', 'completed');

        $this->assertNull($this->store->get('job-3'));
        $this->assertNull($this->store->get('job-that-never-existed'));
    }
}
