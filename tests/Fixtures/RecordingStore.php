<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

use Knobik\HorizonJobOutput\JobOutputStore;

/**
 * A store that records what would have been written, so the output classes can
 * be tested without a Redis server.
 */
class RecordingStore implements JobOutputStore
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $writes = [];

    public function put(string $jobId, string $output): bool
    {
        $this->writes[] = [$jobId, $output];

        return true;
    }

    public function get(string $jobId): ?string
    {
        $last = null;

        foreach ($this->writes as [$id, $output]) {
            if ($id === $jobId) {
                $last = $output;
            }
        }

        return $last;
    }

    public function writeCount(): int
    {
        return count($this->writes);
    }

    public function latest(): ?string
    {
        $last = end($this->writes);

        return $last === false ? null : $last[1];
    }
}
