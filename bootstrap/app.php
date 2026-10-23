<?php

use App\Http\Middleware\EnsureTenantHasFeature;
use App\Http\Middleware\InitializeTenancyUnlessCentral;
use App\Http\Middleware\PreventWritesWhileTenantIsRestricted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenancy must be initialized before the session starts, otherwise the
        // session would be resolved against the wrong database connection.
        // Laravel 11+ replaced App\Http\Kernel, so priority is declared here
        // rather than in TenancyServiceProvider.
        $middleware->prependToPriorityList(
            before: StartSession::class,
            prepend: InitializeTenancyByDomain::class,
        );

        $middleware->prependToPriorityList(
            before: InitializeTenancyByDomain::class,
            prepend: PreventAccessFromCentralDomains::class,
        );

        // Same reason as above, for the Fortify routes shared by both domains.
        $middleware->prependToPriorityList(
            before: StartSession::class,
            prepend: InitializeTenancyUnlessCentral::class,
        );

        // Every tenant route's stack. A suspended or paused agency is read-only,
        // so the write guard sits here rather than on individual routes.
        $middleware->group('tenant', [
            'web',
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
            PreventWritesWhileTenantIsRestricted::class,
        ]);

        // Gates a route on the agency's plan tier; see EnsureTenantHasFeature::using().
        $middleware->alias([
            'feature' => EnsureTenantHasFeature::class,
        ]);

        // Guests opening an admin page are sent to the admin homepage, one step
        // before the super admin login - not to the customer one. The page they
        // asked for is still remembered, so signing in returns them to it.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.homepage')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
