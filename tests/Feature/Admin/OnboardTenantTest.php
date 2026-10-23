<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

/**
 * Open the onboarding page as the given user, filled with a valid agency.
 *
 * @param  array<string, string>  $overrides
 */
function onboardingForm(User $user, array $overrides = []): Testable
{
    $component = Livewire::actingAs($user)->test('pages::admin.tenants.create');

    $input = [
        'name' => 'Valencia City Travel & Tours',
        'subdomain' => 'valencia',
        'locality' => 'Valencia City',
        'currency' => 'PHP',
        'first_name' => 'Ignacio',
        'last_name' => 'Ramos',
        'username' => 'ignacio',
        'email' => 'ignacio@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        ...$overrides,
    ];

    foreach ($input as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

function tenantDatabaseExists(Tenant $tenant): bool
{
    return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
}

test('onboarding provisions a pending agency with its domain, settings, first admin and audit row', function () {
    $superAdmin = User::factory()->superAdmin()->create(['first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']);

    onboardingForm($superAdmin)
        ->call('onboard')
        ->assertHasNoErrors();

    $tenant = Tenant::findOrFail('valencia');
    expect($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->name)->toBe('Valencia City Travel & Tours')
        ->and($tenant->getAttribute('locality'))->toBe('Valencia City')
        ->and($tenant->getAttribute('currency'))->toBe('PHP')
        ->and($tenant->domains()->pluck('domain')->all())->toBe(['valencia.localhost'])
        ->and(tenantDatabaseExists($tenant))->toBeTrue()
        ->and($tenant->run(fn () => DB::table('settings')->count()))->toBe(1);
    $admin = User::where('username', 'ignacio')->sole();
    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->tenant_id)->toBe('valencia')
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check('correct-horse-battery', $admin->password))->toBeTrue();
    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantCreated)
        ->and($event->tenant_id)->toBe('valencia')
        ->and($event->tenant_label)->toBe('Valencia City Travel & Tours')
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->actor_role)->toBe(Role::SuperAdmin)
        ->and($event->actor_label)->toBe('Marisol Vega (Super admin)');
});

test('a subdomain whose domain another tenant already owns is rejected', function () {
    $other = Tenant::factory()->create();
    $other->domains()->create(['domain' => 'valencia.localhost']);

    onboardingForm(User::factory()->superAdmin()->create())
        ->call('onboard')
        ->assertHasErrors(['subdomain' => 'The valencia.localhost domain is already taken.']);

    expect(Tenant::pluck('id')->all())->toBe([$other->id]);
});

test('a subdomain already used as a tenant id is rejected', function () {
    Tenant::factory()->create(['id' => 'valencia']);

    onboardingForm(User::factory()->superAdmin()->create())
        ->call('onboard')
        ->assertHasErrors(['subdomain' => 'The subdomain has already been taken.']);

    expect(User::where('username', 'ignacio')->exists())->toBeFalse();
});

test('a subdomain that cannot name a tenant database is rejected', function (string $subdomain) {
    onboardingForm(User::factory()->superAdmin()->create(), ['subdomain' => $subdomain])
        ->call('onboard')
        ->assertHasErrors(['subdomain' => 'The subdomain may only contain lowercase letters, numbers and single dashes.']);

    expect(Tenant::count())->toBe(0);
})->with([
    'uppercase' => 'Valencia',
    'space' => 'valencia city',
    'underscore' => 'valencia_city',
    'leading dash' => '-valencia',
    'double dash' => 'valencia--city',
]);

test('a subdomain that would collide with a console route is rejected', function () {
    onboardingForm(User::factory()->superAdmin()->create(), ['subdomain' => 'create'])
        ->call('onboard')
        ->assertHasErrors(['subdomain' => 'That subdomain is reserved.']);

    expect(Tenant::count())->toBe(0);
});

test('an unsupported currency is rejected', function () {
    onboardingForm(User::factory()->superAdmin()->create(), ['currency' => 'USD'])
        ->call('onboard')
        ->assertHasErrors(['currency' => 'The selected currency is invalid.']);

    expect(Tenant::count())->toBe(0);
});

test('every agency and admin field is required', function () {
    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.create')
        ->set('currency', '')
        ->call('onboard')
        ->assertHasErrors([
            'name' => 'required',
            'subdomain' => 'required',
            'locality' => 'required',
            'currency' => 'required',
            'first_name' => 'required',
            'last_name' => 'required',
            'username' => 'required',
            'email' => 'required',
            'password' => 'required',
        ]);
});

test('walking through every step onboards the agency', function () {
    onboardingForm(User::factory()->superAdmin()->create())
        ->call('nextStep')
        ->assertSet('step', 2)
        ->call('nextStep')
        ->assertSet('step', 3)
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('step', 1);

    expect(Tenant::pluck('id')->all())->toBe(['valencia']);
});

test('a step with invalid fields cannot be continued past', function () {
    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.create')
        ->set('name', 'Valencia City Travel & Tours')
        ->call('nextStep')
        ->assertHasErrors(['subdomain' => 'required'])
        ->assertHasNoErrors(['first_name'])
        ->assertSet('step', 1);
});

test('an earlier step that fails on the final submit is shown again', function () {
    $other = Tenant::factory()->create();
    $other->domains()->create(['domain' => 'valencia.localhost']);

    onboardingForm(User::factory()->superAdmin()->create())
        ->set('step', 3)
        ->call('nextStep')
        ->assertHasErrors(['subdomain' => 'The valencia.localhost domain is already taken.'])
        ->assertSet('step', 1);
});

test('going back returns to the previous step without losing its answers', function () {
    onboardingForm(User::factory()->superAdmin()->create())
        ->call('nextStep')
        ->call('previousStep')
        ->assertSet('step', 1)
        ->assertSet('subdomain', 'valencia');
});

test('a failure after the tenant is created removes the tenant and its database', function () {
    mock(AuditRecorder::class)
        ->shouldReceive('recordPlatformEvent')
        ->andThrow(new RuntimeException('Audit write failed.'));
    $tenant = new Tenant(['id' => 'valencia']);

    expect(fn () => onboardingForm(User::factory()->superAdmin()->create())->call('onboard'))
        ->toThrow(RuntimeException::class, 'Audit write failed.');

    expect(Tenant::count())->toBe(0)
        ->and(DB::table('domains')->count())->toBe(0)
        ->and(User::where('username', 'ignacio')->exists())->toBeFalse()
        ->and(tenantDatabaseExists($tenant))->toBeFalse();
});

test('the onboarding page is forbidden to tenant admins', function () {
    $admin = User::factory()->role(Role::Admin)->create();

    actingAs($admin)
        ->get(route('admin.tenants.create'))
        ->assertForbidden();
});

test('the onboarding component refuses non-super-admins without the console middleware', function () {
    // Livewire update requests skip the route middleware, so the component's
    // own authorization is what stands between them and onboard().
    Livewire::actingAs(User::factory()->role(Role::Admin)->create())
        ->test('pages::admin.tenants.create')
        ->assertForbidden();
});
