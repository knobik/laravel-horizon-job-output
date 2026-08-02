<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

use Knobik\HorizonJobOutput\Concerns\WritesJobOutput;

class JobWithOutput
{
    use WritesJobOutput;

    public $job;

    public function __construct(?object $job = null)
    {
        $this->job = $job ?? new FakeQueueJob;
    }

    public function handle(): void
    {
        $this->info('working');
    }
}
