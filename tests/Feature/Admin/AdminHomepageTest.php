<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('a guest sees the homepage with a way to sign in', function () {
    get(route('admin.homepage'))
        ->assertOk()
        ->assertSee('Welcome to '.config('app.name'))
        ->assertSee(route('admin.login'));
});

test('the homepage is public, so it shows no platform data', function () {
    Event::fake([TenantCreated::class]);
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete City Travel']);
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantSuspended, User::factory()->superAdmin()->create(), $tenant, 'Unpaid platform fees.');

    get(route('admin.homepage'))
        ->assertOk()
        ->assertDontSee('Dumaguete City Travel')
        ->assertDontSee('Unpaid platform fees.');
});

test('a signed-in super admin skips the homepage for the dashboard', function () {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.homepage'))
        ->assertRedirect(route('admin.dashboard'));
});

test('anyone else signed in sees the homepage like a guest', function (Role $role) {
    actingAs(User::factory()->role($role)->create())
        ->get(route('admin.homepage'))
        ->assertOk()
        ->assertSee(route('admin.login'));
})->with([
    'admin' => Role::Admin,
    'customer' => Role::Customer,
]);
