<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\RedirectAgencyStaffToDashboard;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

// The `tenant` group (bootstrap/app.php) also makes a suspended agency read-only.
Route::middleware('tenant')->group(function () {
    /*
    | The agency's first page: public, and where a guest opening any agency
    | page is sent, one step before the login. Its staff, once signed in, go
    | on to the dashboard.
    */
    Route::view('/', 'pages::tenant.homepage')
        ->middleware(RedirectAgencyStaffToDashboard::class)
        ->name('tenant.homepage');

    // Login itself is Fortify's /login, which serves the tenant portal on tenant domains.
    Route::middleware(['auth', EnsureUserBelongsToTenant::class])->group(function () {
        Route::livewire('dashboard', 'pages::tenant.dashboard')->name('tenant.dashboard');
    });
});
