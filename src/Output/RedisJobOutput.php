<?php

namespace Knobik\HorizonJobOutput\Output;

use Knobik\HorizonJobOutput\JobOutputStore;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\Output;

class RedisJobOutput extends Output
{
    /**
     * The output written so far.
     */
    protected string $buffer = '';

    /**
     * Whether the buffer has reached the configured size limit.
     */
    protected bool $truncated = false;

    /**
     * Unix timestamp, in milliseconds, of the last write to Redis.
     */
    protected float $lastFlushedAt = 0;

    /**
     * Whether the buffer holds anything not yet written to Redis.
     */
    protected bool $dirty = false;

    public function __construct(
        protected JobOutputStore $store,
        protected string $jobId,
        protected int $maxBytes = 65536,
        protected int $flushIntervalMs = 500,
        int $verbosity = self::VERBOSITY_NORMAL,
        bool $decorated = false,
        ?OutputFormatterInterface $formatter = null,
    ) {
        parent::__construct($verbosity, $decorated, $formatter);

        $this->lastFlushedAt = $this->now();
    }

    /**
     * {@inheritdoc}
     */
    protected function doWrite(string $message, bool $newline): void
    {
        $this->append($message.($newline ? PHP_EOL : ''));

        if (($this->now() - $this->lastFlushedAt) >= $this->flushIntervalMs) {
            $this->flush();
        }
    }

    /**
     * Write the buffer to Redis if anything has changed.
     */
    public function flush(bool $force = false): void
    {
        if (! $this->dirty && ! $force) {
            return;
        }

        $this->store->put($this->jobId, $this->buffer);

        $this->dirty = false;
        $this->lastFlushedAt = $this->now();
    }

    /**
     * Get the output written so far.
     */
    public function buffer(): string
    {
        return $this->buffer;
    }

    /**
     * Append to the buffer, respecting the configured size limit.
     */
    protected function append(string $text): void
    {
        if ($this->truncated) {
            return;
        }

        $remaining = $this->maxBytes - strlen($this->buffer);

        if (strlen($text) >= $remaining) {
            $this->buffer .= substr($text, 0, max($remaining, 0));
            $this->buffer .= PHP_EOL.'… output truncated'.PHP_EOL;
            $this->truncated = true;
        } else {
            $this->buffer .= $text;
        }

        $this->dirty = true;
    }

    /**
     * The current time in milliseconds.
     */
    protected function now(): float
    {
        return microtime(true) * 1000;
    }
}
