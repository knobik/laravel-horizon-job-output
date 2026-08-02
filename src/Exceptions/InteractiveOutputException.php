<?php

namespace Knobik\HorizonJobOutput\Exceptions;

use RuntimeException;

class InteractiveOutputException extends RuntimeException
{
    public static function forMethod(string $method): static
    {
        return new static(
            "[{$method}()] cannot be used inside a queued job: a worker has no input stream to read from. ".
            'Remove the call, or gather the value before dispatching the job.'
        );
    }
}
