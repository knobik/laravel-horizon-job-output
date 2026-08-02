<?php

namespace Knobik\HorizonJobOutput\Tests\Fixtures;

class JobOptingOut extends JobWithOutput
{
    public function shouldCaptureOutput(): bool
    {
        return false;
    }
}
