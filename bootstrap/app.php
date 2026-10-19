<?php

use App\Http\Middleware\InitializeTenancyUnlessCentral;
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

        // Guests opening the platform console belong at the super admin login,
        // not the customer one.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
