<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureTenantHasFeature;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\InitializeTenancyUnlessCentral;
use App\Services\Session\DefaultConnectionSessionHandler;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Livewire;
use LogicException;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    /**
     * @return array<class-string, array<int, mixed>>
     */
    public function events(): array
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,

                    // Your own jobs to prepare the tenant.
                    // Provision API keys, create S3 buckets, anything you want!

                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            // Before tenancy counts as initialized, so a refusal leaves the
            // central context whole.
            Events\InitializingTenancy::class => [
                $this->refuseCacheStoreThatCannotTag(...),
            ],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->mapRoutes();
        $this->configureLivewire();
        $this->configureSessions();
    }

    /**
     * Keep each agency's sessions in its own database, the platform's in the
     * central one, by choosing the session's connection when it is read or
     * written (DefaultConnectionSessionHandler explains why that matters).
     */
    protected function configureSessions(): void
    {
        Session::extend('database', fn (Application $app): DefaultConnectionSessionHandler => new DefaultConnectionSessionHandler(
            $app->make(DatabaseManager::class),
            config('session.connection') ?: null,
            config()->string('session.table'),
            config()->integer('session.lifetime'),
            $app,
        ));
    }

    /**
     * Make Livewire's update requests run inside the agency they come from.
     *
     * Every component posts its updates to one shared endpoint, not to the
     * page's route, so none of the page's middleware runs there by default:
     * on an agency domain there would be no tenancy, and so no agency session
     * or database. The endpoint initializes tenancy itself on agency domains
     * and leaves central requests untouched, like the Fortify routes.
     *
     * Persistent middleware is re-run on each update against the page's own
     * route, so a page's staff-only and plan-tier guards hold for its actions
     * too. The read-only guard is deliberately not persistent: every update
     * is a POST, so it would refuse a restricted agency's searching and
     * paging as well. Policies refuse its writes instead (AgencyPolicy).
     */
    protected function configureLivewire(): void
    {
        // Livewire adds the `web` group itself, and the priority list in
        // bootstrap/app.php still initializes tenancy before the session.
        Livewire::setUpdateRoute(fn (array $handle, string $path) => Route::post($path, $handle)
            ->middleware(InitializeTenancyUnlessCentral::class));

        // File uploads post to an endpoint of their own first. Without
        // tenancy there, an agency's upload would land in central storage,
        // and the update that saves it, which runs inside the agency, would
        // not find it. Livewire's own default throttle is kept.
        // The `uploads` disk keeps them where every node can read them (S3
        // when agency files are), since the next request may reach another node.
        config([
            'livewire.temporary_file_upload.middleware' => ['throttle:60,1', InitializeTenancyUnlessCentral::class],
            'livewire.temporary_file_upload.disk' => 'uploads',
        ]);

        // A picked file is previewed from its own endpoint too, which reads it
        // back from the agency's upload folder: without tenancy it would look
        // in the central one and miss it, and start a session outside the agency.
        FilePreviewController::$middleware = ['web', InitializeTenancyUnlessCentral::class];

        Livewire::addPersistentMiddleware([
            EnsureUserBelongsToTenant::class,
            EnsureTenantHasFeature::class,
        ]);
    }

    /**
     * Refuse to enter an agency when the cache store cannot tag.
     *
     * CacheTenancyBootstrapper keeps each agency's cache apart by tagging
     * every call with the agency. On a store without tags (database, file)
     * every cache call inside an agency would throw, but only once some code
     * first caches something there; failing here, on entering any agency,
     * surfaces the misconfiguration at once instead.
     *
     * @throws LogicException
     */
    private function refuseCacheStoreThatCannotTag(): void
    {
        if (! in_array(CacheTenancyBootstrapper::class, config()->array('tenancy.bootstrappers'), true)) {
            return;
        }

        if (! Cache::getStore() instanceof TaggableStore) {
            $store = config()->string('cache.default');

            throw new LogicException("The [{$store}] cache store cannot tag, so it cannot keep each agency's cache apart. Set CACHE_STORE to redis, memcached or array.");
        }
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes(): void
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }
}
