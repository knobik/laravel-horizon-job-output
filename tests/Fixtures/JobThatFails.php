<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

use RuntimeException;

class JobThatFails extends JobWithOutput
{
    public function handle(): void
    {
        $this->info('about to fail');

        throw new RuntimeException('deliberate failure');
    }
}
