<?php

namespace Knobik\HorizonJobOutput\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Knobik\HorizonJobOutput\JobOutputStore;
use Knobik\HorizonJobOutput\Tests\Concerns\InteractsWithRedis;
use Knobik\HorizonJobOutput\Tests\TestCase;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\JobPayload;
use PHPUnit\Framework\Attributes\Test;

class ReservedJobsTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        // Horizon only authorises the dashboard in a local environment.
        Horizon::auth(fn () => true);
    }

    protected function tearDown(): void
    {
        Horizon::auth(fn () => false);

        parent::tearDown();
    }

    /**
     * The queue connection Horizon's workers reserve jobs on. This is the
     * connection the reserved set lives on, which is not Horizon's own.
     */
    protected function queue()
    {
        return Queue::connection('redis');
    }

    /**
     * Put a job into the queue's reserved set.
     *
     * The score is the reservation deadline: once it passes, the job is
     * considered abandoned and Horizon migrates it back onto the queue.
     */
    protected function reserve(string $id, float $expiresIn = 90, array $overrides = [], string $queue = 'default'): array
    {
        $payload = array_merge([
            'uuid' => $id,
            'displayName' => 'App\Jobs\LongJob',
            'attempts' => 1,
            'tags' => ['tag-a', 'tag-b'],
        ], $overrides);

        $this->queue()->getConnection()->zadd(
            "queues:{$queue}:reserved",
            microtime(true) + $expiresIn,
            json_encode($payload)
        );

        return $payload;
    }

    /**
     * Record the job on Horizon's side, the way a worker would.
     */
    protected function track(array $payload): void
    {
        $repository = $this->app->make(JobRepository::class);
        $job = new JobPayload(json_encode($payload));

        $repository->pushed('redis', 'default', $job);
        $repository->reserved('redis', 'default', $job);
    }

    protected function response()
    {
        return $this->getJson('/horizon/api/reserved-jobs');
    }

    protected function reservedJobs(): array
    {
        return $this->response()->json('jobs');
    }

    #[Test]
    public function it_lists_the_jobs_the_workers_currently_hold(): void
    {
        $this->track($this->reserve('job-1'));

        $response = $this->response();

        $response->assertOk();
        $response->assertJsonCount(1, 'jobs');
        $response->assertJsonPath('jobs.0.id', 'job-1');
        $response->assertJsonPath('jobs.0.name', 'App\Jobs\LongJob');
        $response->assertJsonPath('jobs.0.queue', 'default');
        $response->assertJsonPath('jobs.0.connection', 'redis');
        $response->assertJsonPath('jobs.0.expired', false);
    }

    #[Test]
    public function it_reads_the_reservation_deadline_and_the_time_the_job_started(): void
    {
        $this->track($this->reserve('job-1', expiresIn: 60));

        $job = $this->reservedJobs()[0];

        // Both are elapsed times measured server-side, so the page never has to
        // reconcile them against a browser clock that may be skewed.
        $this->assertEqualsWithDelta(60, $job['expires_in'], 5);
        $this->assertEqualsWithDelta(0, $job['running_for'], 5);
    }

    /**
     * A worker that dies leaves its job reserved until the deadline passes and
     * Horizon migrates it back. Surfacing that is the point of the page, so the
     * job must be listed rather than filtered out.
     */
    #[Test]
    public function it_flags_a_reservation_that_has_already_expired(): void
    {
        $this->track($this->reserve('abandoned', expiresIn: -30));

        $response = $this->response();

        $response->assertJsonCount(1, 'jobs');
        $response->assertJsonPath('jobs.0.id', 'abandoned');
        $response->assertJsonPath('jobs.0.expired', true);
    }

    #[Test]
    public function it_reports_whether_a_job_has_output_to_look_at(): void
    {
        $this->track($this->reserve('quiet'));
        $this->track($this->reserve('noisy'));

        $this->app->make(JobOutputStore::class)->put('noisy', 'working...');

        $jobs = collect($this->reservedJobs())->keyBy('id');

        $this->assertTrue($jobs['noisy']['has_output']);
        $this->assertFalse($jobs['quiet']['has_output']);
    }

    /**
     * Horizon trims its job hashes on its own schedule, but the reservation
     * outlives them, so the row falls back to what the payload itself carries.
     */
    #[Test]
    public function it_falls_back_to_the_payload_when_horizon_has_trimmed_the_job(): void
    {
        $this->reserve('untracked');

        $response = $this->response();

        $response->assertJsonCount(1, 'jobs');
        $response->assertJsonPath('jobs.0.id', 'untracked');
        $response->assertJsonPath('jobs.0.name', 'App\Jobs\LongJob');
        $response->assertJsonPath('jobs.0.queue', 'default');
        $response->assertJsonPath('jobs.0.running_for', null);
        $response->assertJsonPath('jobs.0.tags', ['tag-a', 'tag-b']);
    }

    #[Test]
    public function it_returns_nothing_when_no_jobs_are_reserved(): void
    {
        $response = $this->response();

        $response->assertOk();
        $response->assertJsonPath('jobs', []);
    }

    /**
     * The unit tests patch a hand-written fixture, which proves the decorator
     * works but not that it still matches Horizon. This renders the real
     * dashboard through the whole chain — route, view override, decorator — so
     * the sidebar anchor is checked against Horizon's actual markup. It is what
     * the weekly canary run against horizon:dev-master trips on.
     */
    #[Test]
    public function it_adds_the_page_to_the_real_horizon_dashboard(): void
    {
        $response = $this->get('/horizon/reserved');

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('<span>Reserved Jobs</span>', $html);
        $this->assertStringContainsString('<a href="/horizon/reserved"', $html);
        $this->assertStringContainsString('<div id="hjo-page"></div>', $html);

        // The link has to land in the sidebar, not merely somewhere in the page.
        $this->assertStringContainsString('Failed Jobs', $html);
        $this->assertLessThan(
            strpos($html, '</ul>'),
            strpos($html, '<span>Reserved Jobs</span>'),
        );
    }

    /**
     * The endpoint sits under Horizon's prefix, and Horizon ends its routes with
     * a catch-all that matches everything there. Whichever is registered first
     * wins, so this fails the moment the route registration moves out of the
     * provider's register() method into boot().
     */
    #[Test]
    public function it_is_not_swallowed_by_horizons_catch_all_route(): void
    {
        $response = $this->get('/horizon/api/reserved-jobs');

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    /**
     * The endpoint reports job names, queues and tags, so it has to sit behind
     * the same gate as the rest of the dashboard. Horizon applies that on its
     * base controller rather than in the route group, so it is not inherited.
     */
    #[Test]
    public function it_requires_the_same_authorisation_as_the_rest_of_horizon(): void
    {
        Horizon::auth(fn () => false);

        $this->response()->assertForbidden();
    }

    /* ------------------------------------------------ releasing a reservation */

    protected function release(array $overrides = [])
    {
        return $this->postJson('/horizon/api/reserved-jobs/release', array_merge([
            'connection' => 'redis',
            'queue' => 'default',
            'id' => 'job-1',
        ], $overrides));
    }

    protected function queued(string $queue = 'default'): array
    {
        return (array) $this->queue()->getConnection()->lrange("queues:{$queue}", 0, -1);
    }

    protected function stillReserved(string $queue = 'default'): array
    {
        return (array) $this->queue()->getConnection()->zrange("queues:{$queue}:reserved", 0, -1);
    }

    /**
     * The whole point of the button: put the job back where a worker can pick it
     * up, exactly as LuaScripts::migrateExpiredJobs would once the reservation
     * ran out — which for a queue that has lost every worker is never, because
     * that migration only ever runs inside RedisQueue::pop().
     */
    #[Test]
    public function it_releases_a_reserved_job_back_onto_its_queue(): void
    {
        // Not tracked on Horizon's side: the release reads and writes the
        // queue's own keys only, and nothing about it depends on the job hash.
        $this->reserve('job-1', expiresIn: -30);

        $response = $this->release();

        $response->assertOk();
        $response->assertJsonPath('released', true);

        $this->assertSame([], $this->stillReserved());
        $this->assertCount(1, $this->queued());
    }

    /**
     * The payload has to go back byte for byte. A worker's attempt counter, its
     * retry_until deadline and its middleware all travel in it, and Horizon keys
     * its job hash on the id inside.
     */
    #[Test]
    public function it_puts_the_job_back_exactly_as_it_was_reserved(): void
    {
        $payload = $this->reserve('job-1', overrides: ['attempts' => 3]);

        $this->release();

        $this->assertSame([json_encode($payload)], $this->queued());
    }

    /**
     * A worker parked in blpop is woken by the notify list rather than by the
     * queue itself, so a release that skipped it would sit there until the block
     * timed out. migrateExpiredJobs pushes one entry per job for the same reason.
     */
    #[Test]
    public function it_notifies_a_worker_that_is_blocked_waiting_for_work(): void
    {
        $this->reserve('job-1');

        $this->release();

        $this->assertSame(1, $this->queue()->getConnection()->llen('queues:default:notify'));
    }

    /**
     * The page is up to one poll interval out of date, so the job may well have
     * finished between the render and the click. Re-queueing it then would run a
     * completed job a second time, which is what the zrem guard in the script is
     * there to prevent.
     */
    #[Test]
    public function it_releases_nothing_when_the_job_is_no_longer_reserved(): void
    {
        $response = $this->release();

        $response->assertOk();
        $response->assertJsonPath('released', false);

        $this->assertSame([], $this->queued());
    }

    /**
     * The queue name decides the Redis keys, so it is checked against the same
     * configured-plus-running list the page is drawn from rather than trusted.
     */
    #[Test]
    public function it_refuses_a_queue_it_does_not_list(): void
    {
        $this->reserve('job-1', queue: 'unlisted');

        $response = $this->release(['queue' => 'unlisted']);

        $response->assertOk();
        $response->assertJsonPath('released', false);

        $this->assertCount(1, $this->stillReserved('unlisted'));
        $this->assertSame([], $this->queued('unlisted'));
    }

    #[Test]
    public function it_disappears_when_releasing_is_turned_off(): void
    {
        config(['horizon-job-output.release_reservations' => false]);

        $this->reserve('job-1');

        $this->release()->assertNotFound();

        $this->assertCount(1, $this->stillReserved());
    }

    /**
     * Turning the page off has to take its actions with it, not leave an
     * endpoint behind that nothing links to.
     */
    #[Test]
    public function it_goes_with_the_page_when_the_page_is_turned_off(): void
    {
        config(['horizon-job-output.reserved_page' => false]);

        $this->release()->assertNotFound();
    }

    #[Test]
    public function releasing_requires_the_same_authorisation_as_the_rest_of_horizon(): void
    {
        Horizon::auth(fn () => false);

        $this->reserve('job-1');

        $this->release()->assertForbidden();

        $this->assertCount(1, $this->stillReserved());
    }

    #[Test]
    public function it_rejects_a_release_that_names_no_job(): void
    {
        $this->release(['id' => ''])->assertStatus(422);
    }
}
