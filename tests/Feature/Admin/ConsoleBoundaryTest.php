<?php

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\PersonalAccessToken;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withToken;

/*
 * The IDOR pass over /admin/*. The route list is read from the router rather
 * than written out here, so a console page added later is held to the same
 * boundary without anyone remembering to extend this file.
 */

beforeEach(function () {
    // The console reads only central tables, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

/**
 * The admin pages a guest is meant to reach: the homepage and the login.
 *
 * @return list<string>
 */
function publicAdminPages(): array
{
    return ['admin.homepage', 'admin.login'];
}

/**
 * Every console page, as the URL a browser would request. `tenant` fills the
 * {tenant} parameter of the agency page and is a harmless query string on the
 * rest.
 *
 * @return array<string, string>
 */
function consolePages(string $tenantId = 'dumaguete'): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.')
            && in_array('GET', $route->methods(), true)
            && ! in_array($route->getName(), publicAdminPages(), true))
        ->mapWithKeys(fn (LaravelRoute $route): array => [$route->getName() => route($route->getName(), ['tenant' => $tenantId])])
        ->all();
}

/**
 * A bearer token as the customer API will issue them, built the way Sanctum
 * looks tokens up: "{id}|{plain text}", stored hashed.
 */
function bearerTokenFor(User $user): string
{
    $plainText = 'plain-text-'.$user->id;
    $token = $user->morphMany(PersonalAccessToken::class, 'tokenable')->create([
        'name' => 'flutter-app',
        'token' => hash('sha256', $plainText),
        'abilities' => ['*'],
    ]);

    return "{$token->id}|{$plainText}";
}

function agencyPage(Tenant $tenant, ?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->superAdmin()->create())->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('the console has pages to protect', function () {
    expect(array_keys(consolePages()))->toContain('admin.dashboard', 'admin.tenants.index', 'admin.tenants.show', 'admin.audit.index');
});

test('every console page sends a guest to the admin homepage', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);

    foreach (consolePages($tenant->id) as $name => $url) {
        $response = get($url);

        expect($response->isRedirect(route('admin.homepage')))->toBeTrue("[{$name}] answered {$response->status()} to a guest");
    }
});

test('every console page is forbidden to every role but super admin', function (Role $role) {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);
    $user = User::factory()->role($role)->for($tenant)->create();
    // Customers are central users, so one belonging to an agency is the
    // stricter case; a central customer is covered by the bearer token test.
    actingAs($user);

    foreach (consolePages($tenant->id) as $name => $url) {
        $this->assertSame(403, get($url)->status(), "[{$name}] was not forbidden to a {$role->name}");
    }
})->with([
    'admin' => Role::Admin,
    'manager' => Role::Manager,
    'staff' => Role::Staff,
    'customer' => Role::Customer,
]);

test('no console page exists on an agency\'s own domain, even for its admin', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);
    $tenant->domains()->create(['domain' => 'dumaguete.localhost']);
    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create());

    foreach (consolePages($tenant->id) as $name => $url) {
        $onAgencyDomain = 'http://dumaguete.localhost'.parse_url($url, PHP_URL_PATH);

        $this->assertSame(404, get($onAgencyDomain)->status(), "[{$name}] was reachable on the agency's domain");
    }
});

test('a bearer token opens no console page, whoever it belongs to', function (Role $role) {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);
    $token = bearerTokenFor(User::factory()->role($role)->create());

    foreach (consolePages($tenant->id) as $name => $url) {
        $response = withToken($token)->get($url);

        expect($response->isRedirect(route('admin.homepage')))->toBeTrue("[{$name}] answered {$response->status()} to a {$role->name}'s bearer token");
    }
})->with([
    'customer' => Role::Customer,
    // The console is session-only: even a super admin's token is not a way in.
    'super admin' => Role::SuperAdmin,
]);

test('every write on the agency page is refused to anyone but a super admin', function (string $action, Closure $arguments) {
    $tenant = Tenant::factory()->create();
    $invoice = SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create();
    // The page is opened by a super admin and the session then changes hands,
    // as in a stale tab: each action must authorize for itself.
    $page = agencyPage($tenant);
    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create());

    $page->call($action, ...$arguments($invoice))->assertForbidden();

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(Subscription::count())->toBe(1)
        ->and(SubscriptionPayment::count())->toBe(0)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'changing status' => ['changeStatus', fn () => [TenantStatus::Suspended->value]],
    'starting a subscription' => ['startSubscription', fn () => []],
    'opening the payment form' => ['openPaymentForm', fn (SubscriptionInvoice $invoice) => [$invoice->id]],
    'deleting a record' => ['confirmDelete', fn (SubscriptionInvoice $invoice) => ['invoice', $invoice->id]],
    'deleting the agency' => ['deleteAgency', fn () => []],
]);
