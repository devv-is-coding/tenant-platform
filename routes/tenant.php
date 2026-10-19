<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserBelongsToTenant;
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
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });

    // Login itself is Fortify's /login, which serves the tenant portal on tenant domains.
    Route::middleware(['auth', EnsureUserBelongsToTenant::class])->group(function () {
        Route::view('dashboard', 'tenant.dashboard')->name('tenant.dashboard');
    });
});
