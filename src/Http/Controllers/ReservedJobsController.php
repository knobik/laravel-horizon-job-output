<?php

namespace Knobik\HorizonJobOutput\Http\Controllers;

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
}
