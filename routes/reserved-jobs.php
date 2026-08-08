<?php

use Illuminate\Support\Facades\Route;
use Knobik\HorizonJobOutput\Http\Controllers\ReservedJobsController;
use Laravel\Horizon\Http\Middleware\Authenticate;

// The page itself needs no route: Horizon's catch-all already serves its layout
// for any path under the dashboard, and the panel renders into it client-side.
//
// Authenticate wraps the file rather than being hung on each route, because the
// reason for it is a property of everything this package registers, not of one
// endpoint. Horizon puts that middleware on its base controller instead of in
// the route group, so a route that does not extend that controller gets no
// authorisation at all — and these report job names, tags and queues, and one of
// them writes. Applied here, a route added later cannot forget it.
Route::middleware(Authenticate::class)->group(function () {
    Route::get('/api/reserved-jobs', [ReservedJobsController::class, 'index'])
        ->name('horizon-job-output.reserved-jobs.index');

    // The job is identified by all three of connection, queue and id, and they
    // are sent in the body rather than the path: it takes the connection and the
    // queue to know which reserved set to look in at all, and a queue name is
    // free-form enough to carry characters that do not belong in a URL segment.
    //
    // Horizon's catch-all is a GET route, so this one is in no danger of being
    // swallowed by it, but it is registered alongside its sibling all the same.
    Route::post('/api/reserved-jobs/release', [ReservedJobsController::class, 'release'])
        ->name('horizon-job-output.reserved-jobs.release');
});
