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
    | Reserved Jobs Page
    |--------------------------------------------------------------------------
    |
    | Adds a "Reserved Jobs" page to the Horizon dashboard listing the jobs the
    | workers currently hold, including any whose reservation has expired
    | because the worker died. Disable to leave Horizon's navigation untouched.
    |
    */

    'reserved_page' => env('HORIZON_JOB_OUTPUT_RESERVED_PAGE', true),

    /*
    |--------------------------------------------------------------------------
    | Releasing Reservations
    |--------------------------------------------------------------------------
    |
    | Adds a "Release" button to each row of the Reserved Jobs page, which puts
    | that job straight back onto its queue. It is what Laravel does by itself
    | once a reservation runs out, and it exists here for the case that never
    | reaches: the migration only runs while a worker is polling the queue, so a
    | queue that has lost all of its workers keeps its jobs reserved forever.
    |
    | Nothing is discarded — the job is queued again, not deleted. But a
    | reservation that has not expired is one a worker may still be working
    | through, and releasing that puts a second copy onto the queue for both to
    | run. The job also keeps its attempt count, so one already on its last try
    | comes back only to be failed. The dashboard says both before it acts; set
    | this to false to take the option away entirely.
    |
    */

    'release_reservations' => env('HORIZON_JOB_OUTPUT_RELEASE_RESERVATIONS', true),

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
    | Verbosity
    |--------------------------------------------------------------------------
    |
    | The level jobs write at, named as Artisan's flags: "quiet", "normal", "v",
    | "vv" or "vvv". It decides which of the write helpers' optional verbosity
    | arguments are honoured — $this->info('detail', 'vv') is discarded below
    | that level — and how much a progress bar reports, exactly as it does for a
    | command: "v" adds the elapsed time, "vv" the estimate, "vvv" the memory.
    |
    | A single job can override this by implementing outputVerbosity(), which is
    | usually the better place for it: the long job whose progress is worth
    | timing is rarely every job in the application.
    |
    | Note that "quiet" suppresses everything, progress bars included.
    |
    */

    'verbosity' => env('HORIZON_JOB_OUTPUT_VERBOSITY', 'normal'),

    /*
    |--------------------------------------------------------------------------
    | Artisan Commands
    |--------------------------------------------------------------------------
    |
    | Whether an Artisan command writes into the job that ran it. This covers
    | commands queued with Artisan::queue(), whose output has nowhere else to
    | go, and commands a job runs itself with Artisan::call().
    |
    | Worth turning off if your jobs call commands that say a great deal: their
    | output shares the job's max_bytes budget, so a talkative command can crowd
    | out what the job itself wrote.
    |
    */

    'capture_artisan' => env('HORIZON_JOB_OUTPUT_CAPTURE_ARTISAN', true),

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
