<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

class JobWithoutTrait
{
    public $job;

    public function __construct()
    {
        $this->job = new FakeQueueJob;
    }

    public function handle(): void
    {
        //
    }
}
