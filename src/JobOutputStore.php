<?php

namespace Knobik\HorizonJobOutput;

interface JobOutputStore
{
    /**
     * The hash field the output is stored under.
     */
    public const FIELD = 'output';

    /**
     * Store the output for the given job, reporting whether it was kept.
     */
    public function put(string $jobId, string $output): bool;

    /**
     * Get the stored output for the given job.
     */
    public function get(string $jobId): ?string;
}
