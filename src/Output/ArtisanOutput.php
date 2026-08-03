<?php

namespace Knobik\HorizonJobOutput\Output;

use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes an Artisan command's output into a job's output, keeping a copy of it.
 *
 * The copy is what keeps Artisan::output() working. The console application
 * hands back the last command's output only if the buffer it wrote to can
 * fetch() it again, so without this a job that reads the output of a command it
 * ran would quietly start receiving an empty string.
 */
class ArtisanOutput extends Output
{
    protected string $buffer = '';

    public function __construct(protected OutputInterface $target)
    {
        // The formatter is cloned rather than shared: a command run with
        // --no-ansi, or one registering a style of its own, would otherwise
        // reach back into the output of the job that ran it.
        parent::__construct($target->getVerbosity(), $target->isDecorated(), clone $target->getFormatter());
    }

    /**
     * Take everything written since this was last called, as a BufferedOutput
     * does — that method is the whole reason this class exists.
     */
    public function fetch(): string
    {
        $buffer = $this->buffer;

        $this->buffer = '';

        return $buffer;
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message.($newline ? PHP_EOL : '');

        // Passed on raw: this instance has already run the message through its
        // formatter, and a second pass would be at best pointless and at worst
        // a reinterpretation of text that now holds escape sequences.
        $this->target->write($message, $newline, OutputInterface::OUTPUT_RAW);
    }
}
