<?php

use Illuminate\Support\Facades\Route;
use Knobik\HorizonJobOutput\Http\Controllers\ReservedJobsController;
use Laravel\Horizon\Http\Middleware\Authenticate;

// The page itself needs no route: Horizon's catch-all already serves its layout
// for any path under the dashboard, and the panel renders into it client-side.
//
// Authenticate is applied here rather than inherited. Horizon puts it on its
// base controller instead of in the route group, so a route that does not extend
// that controller gets no authorisation at all — and this endpoint reports job
// names, tags and queues.
Route::get('/api/reserved-jobs', [ReservedJobsController::class, 'index'])
    ->middleware(Authenticate::class)
    ->name('horizon-job-output.reserved-jobs.index');
