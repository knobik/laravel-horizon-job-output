<?php

namespace Knobik\HorizonJobOutput\Tests\Unit;

use Illuminate\Console\OutputStyle;
use Knobik\HorizonJobOutput\Exceptions\InteractiveOutputException;
use Knobik\HorizonJobOutput\Tests\Fixtures\JobWithOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class WritesJobOutputTest extends TestCase
{
    protected function job(): array
    {
        $job = new JobWithOutput();
        $job->setOutput(new OutputStyle(new ArrayInput([]), $buffer = new BufferedOutput()));

        return [$job, $buffer];
    }

    public static function interactiveMethods(): array
    {
        return [
            'confirm' => ['confirm', ['Continue?']],
            'ask' => ['ask', ['Name?']],
            'anticipate' => ['anticipate', ['Name?', ['a', 'b']]],
            'askWithCompletion' => ['askWithCompletion', ['Name?', ['a', 'b']]],
            'secret' => ['secret', ['Password?']],
            'choice' => ['choice', ['Pick one', ['a', 'b']]],
        ];
    }

    /**
     * A worker has no input stream, so these would block until the job timed
     * out. Failing immediately, with an explanation, beats a mystery hang.
     */
    #[Test]
    #[DataProvider('interactiveMethods')]
    public function it_refuses_to_prompt_from_a_queued_job(string $method, array $arguments): void
    {
        [$job] = $this->job();

        $this->expectException(InteractiveOutputException::class);
        $this->expectExceptionMessage('cannot be used inside a queued job');

        $job->{$method}(...$arguments);
    }

    #[Test]
    public function it_writes_through_the_console_helpers(): void
    {
        [$job, $buffer] = $this->job();

        $job->info('an informational line');
        $job->comment('a comment');
        $job->error('a problem');
        $job->table(['id'], [[1]]);

        $output = $buffer->fetch();

        $this->assertStringContainsString('an informational line', $output);
        $this->assertStringContainsString('a comment', $output);
        $this->assertStringContainsString('a problem', $output);
        $this->assertStringContainsString('| 1', $output);
    }

    #[Test]
    public function it_captures_output_by_default(): void
    {
        [$job] = $this->job();

        $this->assertTrue($job->shouldCaptureOutput());
    }
}
