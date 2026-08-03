<?php

namespace Knobik\HorizonJobOutput;

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Knobik\HorizonJobOutput\Pipes\CaptureJobOutput;
use Laravel\Horizon\Contracts\JobRepository;
use ReflectionProperty;
use Throwable;

class HorizonJobOutputServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/horizon-job-output.php', 'horizon-job-output');

        $this->app->singleton(
            JobOutputStore::class,
            fn ($app) => new RedisJobOutputStore($app->make(RedisFactory::class))
        );

        // Registered here rather than in boot(). Horizon's dashboard ends in a
        // catch-all route matching everything under its prefix, added from its
        // own boot(), and whichever route is registered first wins. Every
        // provider's register() runs before any provider's boot(), so this is
        // the only placement that beats the catch-all no matter what order the
        // packages were discovered in.
        $this->registerRoutes();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/horizon-job-output.php' => config_path('horizon-job-output.php'),
            ], 'horizon-job-output-config');
        }

        // Deferred until every provider has booted so that Horizon's repository
        // binding and view namespace are both already in place.
        $this->app->booted(function () {
            // The pipe is registered even when the package is disabled. It is
            // what hands each job its output instance, and a job using the
            // trait would fatal on a null output without one — disabling the
            // package must stop it recording, not break the jobs.
            $this->registerBusPipe();

            if (! config('horizon-job-output.enabled', true)) {
                return;
            }

            // Deferred rather than eager: a request that never touches Horizon
            // or renders a view should not pay to construct its job repository
            // or Blade's view finder. callAfterResolving still fires
            // immediately if something resolved them already.
            $this->callAfterResolving(JobRepository::class, fn ($repository) => $this->exposeOutputOnJobRepository($repository));
            $this->callAfterResolving('view', fn ($view) => $this->registerViewOverride($view));
        });
    }

    /**
     * Register the reserved jobs endpoint inside Horizon's route group.
     *
     * The group is rebuilt here rather than reused because Horizon has not
     * booted yet — see the note at the call site. Only the middleware group name
     * is referenced, and middleware groups resolve per request, so the group
     * Horizon defines later is still the one that runs. horizon.path is read
     * with a fallback for the same reason: a customised path always comes from a
     * published config file, which is loaded before any provider runs, so the
     * fallback only ever covers Horizon's own default.
     */
    protected function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        // The route is registered unconditionally and the reserved_page toggle
        // is enforced in the controller instead. Gating it here would write the
        // setting into a cached route table, so flipping it would need a
        // route:clear to take effect.
        Route::group([
            'domain' => config('horizon.domain', null),
            'prefix' => config('horizon.path', 'horizon'),
            'middleware' => 'horizon',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/reserved-jobs.php');
        });
    }

    /**
     * Add the output field to the keys Horizon reads off each job hash.
     *
     * RedisJobRepository reads a fixed whitelist with HMGET rather than HGETALL,
     * so without this the field exists in Redis but never reaches the dashboard
     * API. The repository is a singleton, so this applies process-wide.
     */
    protected function exposeOutputOnJobRepository(object $repository): void
    {
        if (! property_exists($repository, 'keys')) {
            return;
        }

        if (! in_array(JobOutputStore::FIELD, $repository->keys, true)) {
            $repository->keys[] = JobOutputStore::FIELD;
        }
    }

    /**
     * Register the bus pipe that attaches an output instance to opted-in jobs.
     *
     * Dispatcher::pipeThrough() replaces the pipe list outright and there is no
     * getter, so the existing pipes are read reflectively and preserved.
     */
    protected function registerBusPipe(): void
    {
        $dispatcher = $this->app->make(BusDispatcherContract::class);

        if (! $dispatcher instanceof BusDispatcher) {
            return;
        }

        try {
            $property = new ReflectionProperty($dispatcher, 'pipes');
            $pipes = (array) $property->getValue($dispatcher);
        } catch (Throwable) {
            $pipes = [];
        }

        if (in_array(CaptureJobOutput::class, $pipes, true)) {
            return;
        }

        $pipes[] = CaptureJobOutput::class;

        $dispatcher->pipeThrough($pipes);
    }

    /**
     * Take over the horizon::layout view so the output panel can be injected.
     *
     * Horizon's own view path is kept reachable under a second namespace, which
     * lets the override render the real layout and patch it rather than ship a
     * copy that drifts on every Horizon release.
     */
    protected function registerViewOverride($view): void
    {
        $finder = $view->getFinder();

        $hints = $finder->getHints();

        if (! isset($hints['horizon'])) {
            return;
        }

        $finder->addNamespace('horizon-original', $hints['horizon']);
        $finder->prependNamespace('horizon', __DIR__.'/../resources/views');
    }
}
