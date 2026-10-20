<?php

use App\Enums\Role;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    // The console reads only central tables, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

test('the overdue list shows every agency\'s overdue invoices, longest overdue first, with what is still owed', function () {
    travelTo('2026-10-21 09:00:00');
    $dumaguete = Subscription::factory()->for(Tenant::factory()->create(['name' => 'Dumaguete Travel']))->create();
    $tanjay = Subscription::factory()->for(Tenant::factory()->create(['name' => 'Tanjay Travel']))->create();
    $longest = SubscriptionInvoice::factory()->for($tanjay)->create(['reference' => 'PLAT-2026-10-0001', 'period_start_on' => '2026-09-21', 'due_on' => '2026-10-01', 'tenant_id' => $tanjay->tenant_id]);
    $partlyPaid = SubscriptionInvoice::factory()->for($dumaguete)->partiallyPaid(100000)->create(['reference' => 'PLAT-2026-10-0002', 'period_start_on' => '2026-10-05', 'due_on' => '2026-10-15', 'tenant_id' => $dumaguete->tenant_id]);
    SubscriptionInvoice::factory()->for($dumaguete)->create(['reference' => 'PLAT-2026-11-0001', 'period_start_on' => '2026-10-20', 'due_on' => '2026-10-30', 'tenant_id' => $dumaguete->tenant_id]);
    SubscriptionInvoice::factory()->for($tanjay)->paid()->create(['reference' => 'PLAT-2026-09-0001', 'period_start_on' => '2026-08-21', 'due_on' => '2026-09-20', 'tenant_id' => $tanjay->tenant_id]);

    $page = Livewire::actingAs(User::factory()->superAdmin()->create())->test('pages::admin.invoices.overdue');

    expect($page->instance()->invoices->pluck('id')->all())->toBe([$longest->id, $partlyPaid->id]);
    $page->assertSeeInOrder(['Tanjay Travel', 'PLAT-2026-10-0001', '20 days overdue', '₱2,500.00', 'Dumaguete Travel', 'PLAT-2026-10-0002', '6 days overdue', '₱1,500.00'])
        ->assertDontSee('PLAT-2026-11-0001')
        ->assertDontSee('PLAT-2026-09-0001');
});

test('the overdue list says so when nothing is overdue', function () {
    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.invoices.overdue')
        ->assertSee('No invoices are overdue.');
});

test('the agency detail page lists only that agency\'s invoices and flags the overdue ones', function () {
    travelTo('2026-10-21 09:00:00');
    $own = Subscription::factory()->create();
    $other = Subscription::factory()->create();
    SubscriptionInvoice::factory()->for($own)->create(['reference' => 'PLAT-2026-10-0001', 'due_on' => '2026-10-15', 'tenant_id' => $own->tenant_id]);
    SubscriptionInvoice::factory()->for($other)->create(['reference' => 'PLAT-2026-10-0002', 'tenant_id' => $other->tenant_id]);

    $page = Livewire::actingAs(User::factory()->superAdmin()->create())->test('pages::admin.tenants.show', ['tenant' => $own->tenant]);

    expect($page->instance()->invoices->pluck('reference')->all())->toBe(['PLAT-2026-10-0001']);
    $page->assertSeeInOrder(['Invoices', 'PLAT-2026-10-0001', 'Open', 'Overdue']);
});

test('the overdue list is forbidden to an agency\'s own admin', function () {
    $tenant = Tenant::factory()->create();

    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create())
        ->get(route('admin.invoices.overdue'))
        ->assertForbidden();
});

test('the overdue component refuses non-super-admins without the console middleware', function () {
    Livewire::actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create())
        ->test('pages::admin.invoices.overdue')
        ->assertForbidden();
});
