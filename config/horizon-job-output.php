<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled the package registers itself but captures nothing and adds
    | nothing to the Horizon dashboard, which is useful for switching the
    | feature off in a single environment.
    |
    */

    'enabled' => env('HORIZON_JOB_OUTPUT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Maximum Output Size
    |--------------------------------------------------------------------------
    |
    | Output is stored as a field on Horizon's job hash, so a runaway job would
    | otherwise be able to write an unbounded amount of data into Redis. Once a
    | job passes this many bytes its output is truncated.
    |
    */

    'max_bytes' => env('HORIZON_JOB_OUTPUT_MAX_BYTES', 65536),

    /*
    |--------------------------------------------------------------------------
    | Flush Interval
    |--------------------------------------------------------------------------
    |
    | How often, in milliseconds, a running job writes its buffered output to
    | Redis. Each flush rewrites the whole field, so lower values give smoother
    | live output at the cost of more work per job.
    |
    */

    'flush_interval_ms' => env('HORIZON_JOB_OUTPUT_FLUSH_INTERVAL', 500),

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | How often, in milliseconds, the dashboard polls for new output while a job
    | is still running. Polling stops once the job has finished.
    |
    */

    'poll_interval_ms' => env('HORIZON_JOB_OUTPUT_POLL_INTERVAL', 2000),

    /*
    |--------------------------------------------------------------------------
    | ANSI Colours
    |--------------------------------------------------------------------------
    |
    | When enabled, style tags such as <info> and <error> are stored as ANSI
    | escape sequences and rendered as colours in the dashboard. Disable to
    | store plain text instead.
    |
    */

    'ansi' => env('HORIZON_JOB_OUTPUT_ANSI', true),

    /*
    |--------------------------------------------------------------------------
    | Renderer
    |--------------------------------------------------------------------------
    |
    | "terminal" inlines a vendored xterm.js build and renders output through a
    | real terminal emulator, so a progress bar redraws in place exactly as it
    | would in a shell. It adds roughly 345KB to each dashboard page.
    |
    | "html" renders the output as styled HTML instead. Nothing extra is loaded,
    | but sequences that rewrite the current line are collapsed, so a progress
    | bar shows only its final state.
    |
    */

    'renderer' => env('HORIZON_JOB_OUTPUT_RENDERER', 'terminal'),

    /*
    |--------------------------------------------------------------------------
    | Terminal Width
    |--------------------------------------------------------------------------
    |
    | The column count the terminal renders at. This should match the width the
    | job wrote its output at — Symfony assumes 80 columns when a command is not
    | attached to a terminal, which is always the case on a queue worker.
    |
    */

    'columns' => env('HORIZON_JOB_OUTPUT_COLUMNS', 80),

];
