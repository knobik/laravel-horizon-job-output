<?php

namespace Knobik\HorizonJobOutput\Http\Controllers;

use Illuminate\Http\Request;
use Knobik\HorizonJobOutput\Repositories\ReservedJobs;

class ReservedJobsController
{
    public function __construct(protected ReservedJobs $reserved) {}

    /**
     * List the jobs the workers currently hold.
     *
     * The toggle is checked here rather than around the route registration: the
     * route is registered before Horizon's catch-all can claim the path, and
     * that happens once at boot, so gating it there would bake the setting into
     * a cached route table and leave `route:cache` disagreeing with the config.
     */
    public function index(): array
    {
        abort_unless(config('horizon-job-output.reserved_page', true), 404);

        return ['jobs' => $this->reserved->all()->all()];
    }

    /**
     * Put one reserved job back onto its queue.
     *
     * Both toggles are checked, and for the reason given on index(): the page
     * being off has to take its actions with it, and neither can be enforced
     * around the registration.
     *
     * `released` is false rather than a 404 when the job is no longer reserved.
     * The page is up to one poll interval out of date, so a button that has just
     * gone stale is an ordinary outcome, not an error — and it is the same
     * answer whether the worker finished the job or another release won the
     * race.
     */
    public function release(Request $request): array
    {
        abort_unless(config('horizon-job-output.reserved_page', true), 404);
        abort_unless(config('horizon-job-output.release_reservations', true), 404);

        $validated = $request->validate([
            'connection' => ['required', 'string'],
            'queue' => ['required', 'string'],
            'id' => ['required', 'string'],
        ]);

        return ['released' => $this->reserved->release(
            $validated['connection'], $validated['queue'], $validated['id']
        )];
    }
}
