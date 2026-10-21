<?php

use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\RedirectSuperAdminsToAdminDashboard;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;

/*
| Central routes are constrained to the central host so they do not collide with
| routes/tenant.php, which registers its routes without a domain constraint and
| is loaded later - a same-URI tenant route would otherwise overwrite the central
| one, taking its route name with it.
|
| A single host is used rather than looping over tenancy.central_domains because
| registering the same route name once per domain breaks `route:cache`.
*/
Route::domain((string) parse_url((string) config('app.url'), PHP_URL_HOST))->group(function () {
    Route::view('/', 'welcome')->name('home');

    /*
    | Super admin login. It posts through Fortify's login pipeline like /login does,
    | so throttling and two-factor apply; LoginPortal restricts it to super admins.
    */
    Route::middleware('guest:'.config('fortify.guard'))->group(function () {
        Route::view('admin/login', 'pages::auth.admin-login')->name('admin.login');

        Route::post('admin/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:'.config('fortify.limiters.login'))
            ->name('admin.login.store');

        // A separate passkey endpoint so LoginPortal can tell an admin passkey
        // sign-in from a customer one; Fortify's passkey.login serves customers.
        Route::post('admin/passkeys/login', [PasskeyLoginController::class, 'store'])
            ->middleware('throttle:'.config('fortify.limiters.passkeys'))
            ->name('admin.passkey.login');
    });

    /*
    | The super admin's first page: public, and where a guest opening any admin
    | page is sent, one step before the login. A super admin already signed in
    | goes on to the dashboard.
    */
    Route::view('admin/homepage', 'pages::admin.homepage')
        ->middleware(RedirectSuperAdminsToAdminDashboard::class)
        ->name('admin.homepage');

    Route::middleware(['auth', 'verified', EnsureUserIsSuperAdmin::class])
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');

            Route::livewire('tenants', 'pages::admin.tenants.index')->name('tenants.index');

            // Registered before tenants/{tenant} so "create" is not read as a tenant id.
            Route::livewire('tenants/create', 'pages::admin.tenants.create')->name('tenants.create');

            Route::livewire('tenants/{tenant}', 'pages::admin.tenants.show')->name('tenants.show');

            Route::livewire('invoices/overdue', 'pages::admin.invoices.overdue')->name('invoices.overdue');

            Route::livewire('audit', 'pages::admin.audit.index')->name('audit.index');
        });

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::view('dashboard', 'dashboard')
            ->middleware(RedirectSuperAdminsToAdminDashboard::class)
            ->name('dashboard');
    });

    require __DIR__.'/settings.php';
});
