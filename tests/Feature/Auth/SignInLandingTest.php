<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Where each way of signing in lands. A super admin goes straight to the admin
 * dashboard, with no hop through the customer /dashboard; a page asked for
 * before signing in still wins.
 */

/**
 * Resolve the passkey sign-in response, as the passkey package does, for a
 * request made by the given user.
 */
function passkeyLandingFor(User $user): string
{
    $request = Request::create('/admin/passkeys/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn () => $user);

    return app(PasskeyLoginResponse::class)->toResponse($request)->getData(true)['redirect'];
}

test('a super admin passing two-factor lands on the admin dashboard', function () {
    skipUnlessFortifyHas(Features::twoFactorAuthentication());
    $superAdmin = User::factory()->superAdmin()->withTwoFactor()->create();

    post(route('admin.login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('admin.dashboard'));

    assertAuthenticatedAs($superAdmin);
});

test('a super admin signing in with a passkey lands on the admin dashboard', function () {
    expect(passkeyLandingFor(User::factory()->superAdmin()->create()))->toBe(route('admin.dashboard'));
});

test('a customer signing in with a passkey still lands on the customer dashboard', function () {
    expect(passkeyLandingFor(User::factory()->create()))->toBe(url((string) config('passkeys.redirect')));
});

test('a customer signing in with a password still lands on the customer dashboard', function () {
    $customer = User::factory()->create();

    post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('a super admin who opened a deep link first is returned to it after signing in', function () {
    Event::fake([TenantCreated::class]);
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);
    $superAdmin = User::factory()->superAdmin()->create();

    get(route('admin.tenants.show', $tenant))->assertRedirect(route('admin.homepage'));
    get(route('admin.homepage'))->assertOk();

    post(route('admin.login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.tenants.show', $tenant));
});
