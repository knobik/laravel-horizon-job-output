<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

/**
 * Stands in for the queue job a command is attached to while it runs. Only the
 * uuid is needed: it is the key of the Horizon hash the output is stored on.
 */
class FakeQueueJob
{
    public function __construct(protected ?string $uuid = 'job-uuid') {}

    public function uuid(): ?string
    {
        return $this->uuid;
    }
}
