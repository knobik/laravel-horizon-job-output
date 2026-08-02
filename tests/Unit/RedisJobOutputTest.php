<?php

namespace Knobik\HorizonJobOutput\Tests\Unit;

use Knobik\HorizonJobOutput\Output\RedisJobOutput;
use Knobik\HorizonJobOutput\Tests\Fixtures\RecordingStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RedisJobOutputTest extends TestCase
{
    protected function makeOutput(RecordingStore $store, int $maxBytes = 65536, int $flushMs = 500): RedisJobOutput
    {
        return new RedisJobOutput(
            store: $store,
            jobId: 'job-1',
            maxBytes: $maxBytes,
            flushIntervalMs: $flushMs,
        );
    }

    #[Test]
    public function it_accumulates_written_lines(): void
    {
        $output = $this->makeOutput($store = new RecordingStore());

        $output->writeln('first');
        $output->writeln('second');
        $output->flush(force: true);

        $this->assertSame("first\nsecond\n", $store->latest());
    }

    /**
     * Every flush rewrites the whole hash field, so a job printing thousands of
     * lines must not mean thousands of round trips.
     */
    #[Test]
    public function it_batches_writes_within_the_flush_interval(): void
    {
        $output = $this->makeOutput($store = new RecordingStore(), flushMs: 60_000);

        for ($i = 0; $i < 50; $i++) {
            $output->writeln("line {$i}");
        }

        $this->assertSame(0, $store->writeCount(), 'Nothing should reach Redis before the interval elapses.');

        $output->flush(force: true);

        $this->assertSame(1, $store->writeCount());
    }

    #[Test]
    public function it_flushes_once_the_interval_has_elapsed(): void
    {
        $output = $this->makeOutput($store = new RecordingStore(), flushMs: 0);

        $output->writeln('immediate');

        $this->assertSame(1, $store->writeCount());
    }

    #[Test]
    public function it_does_not_write_again_when_nothing_changed(): void
    {
        $output = $this->makeOutput($store = new RecordingStore(), flushMs: 60_000);

        $output->writeln('only line');
        $output->flush(force: true);
        $output->flush();
        $output->flush();

        $this->assertSame(1, $store->writeCount());
    }

    /**
     * Output shares a key with the job itself, so a runaway job must not be able
     * to write an unbounded amount of data into Redis.
     */
    #[Test]
    public function it_truncates_output_at_the_configured_limit(): void
    {
        $output = $this->makeOutput($store = new RecordingStore(), maxBytes: 200, flushMs: 60_000);

        for ($i = 0; $i < 500; $i++) {
            $output->writeln(str_repeat('x', 40));
        }

        $output->flush(force: true);

        $written = $store->latest();

        $this->assertLessThan(300, strlen($written));
        $this->assertStringContainsString('output truncated', $written);
    }

    #[Test]
    public function it_stops_appending_after_truncation(): void
    {
        $output = $this->makeOutput($store = new RecordingStore(), maxBytes: 50, flushMs: 60_000);

        $output->writeln(str_repeat('x', 100));
        $output->flush(force: true);
        $lengthAtTruncation = strlen($store->latest());

        $output->writeln('this must not appear');
        $output->flush(force: true);

        $this->assertSame($lengthAtTruncation, strlen($store->latest()));
        $this->assertStringNotContainsString('this must not appear', $store->latest());
    }

    #[Test]
    public function it_writes_to_the_job_it_was_built_for(): void
    {
        $output = $this->makeOutput($store = new RecordingStore());

        $output->writeln('hello');
        $output->flush(force: true);

        $this->assertSame('job-1', $store->writes[0][0]);
    }
}
