<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // The dashboard reads only central tables, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

function adminDashboard(): Testable
{
    return Livewire::actingAs(User::factory()->superAdmin()->create())->test('pages::admin.dashboard');
}

/**
 * Four agencies, their subscriptions, invoices, payments and people, each
 * chosen so that one thing counts and its look-alike does not.
 *
 * @return array{dumaguete: Tenant, tanjay: Tenant, bais: Tenant, sibulan: Tenant, renewing: Subscription, recent: SubscriptionPayment, lastMonth: SubscriptionPayment}
 */
function seedPlatform(): array
{
    travelTo('2026-10-21 09:00:00');

    $dumaguete = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete Travel']);
    $tanjay = Tenant::factory()->create(['id' => 'tanjay', 'name' => 'Tanjay Travel']);
    $bais = Tenant::factory()->create(['id' => 'bais', 'name' => 'Bais Travel', 'status' => TenantStatus::Pending]);
    $sibulan = Tenant::factory()->create(['id' => 'sibulan', 'name' => 'Sibulan Travel', 'status' => TenantStatus::Suspended, 'status_reason' => 'Unpaid platform fees.']);

    // Live: Dumaguete Active, renewing in three days; Tanjay past due.
    // Sibulan's subscription has ended and must not count as live.
    $renewing = Subscription::factory()->for($dumaguete)->create(['plan_name' => 'Agency Standard', 'next_billing_on' => '2026-10-24']);
    $pastDue = Subscription::factory()->for($tanjay)->pastDue()->create(['plan_name' => 'Agency Plus']);
    $ended = Subscription::factory()->for($sibulan)->expired()->create();

    // Owed: Tanjay's 2,500.00 is overdue; Dumaguete owes 1,500.00 not yet due.
    // A paid invoice and a void overdue one owe nothing.
    SubscriptionInvoice::factory()->for($pastDue)->overdue()->create(['amount_minor' => 250000]);
    $partlyPaid = SubscriptionInvoice::factory()->for($renewing)->partiallyPaid(100000)->create(['amount_minor' => 250000]);
    SubscriptionInvoice::factory()->for($renewing)->paid()->create(['period_start_on' => '2026-09-21']);
    SubscriptionInvoice::factory()->for($ended)->overdue()->void()->create();

    // Received this month: 1,000.00. A failed payment this month and a
    // payment last month do not count.
    $recent = SubscriptionPayment::factory()->for($partlyPaid, 'invoice')->create(['amount_minor' => 100000, 'paid_on' => '2026-10-20 10:00:00']);
    SubscriptionPayment::factory()->for($partlyPaid, 'invoice')->failed()->create(['amount_minor' => 99900, 'paid_on' => '2026-10-21 08:00:00']);
    $lastMonth = SubscriptionPayment::factory()->for($partlyPaid, 'invoice')->create(['amount_minor' => 50000, 'paid_on' => '2026-09-30 23:00:00']);

    // People: Dumaguete has an active admin, Tanjay only a manager, and the
    // other two nobody - so three agencies lack an active admin.
    User::factory()->role(Role::Admin)->for($dumaguete)->create();
    User::factory()->role(Role::Staff)->for($dumaguete)->create();
    User::factory()->role(Role::Staff)->for($dumaguete)->inactive()->create();
    User::factory()->role(Role::Manager)->for($tanjay)->create();
    User::factory()->role(Role::Customer)->for($dumaguete)->create();

    return compact('dumaguete', 'tanjay', 'bais', 'sibulan', 'renewing', 'recent', 'lastMonth');
}

test('the agency figures count every status, including those at zero', function () {
    seedPlatform();

    $dashboard = adminDashboard()->instance();

    expect($dashboard->agencyCounts)->toBe([
        TenantStatus::Pending->value => 1,
        TenantStatus::Active->value => 2,
        TenantStatus::Paused->value => 0,
        TenantStatus::Suspended->value => 1,
        TenantStatus::Closed->value => 0,
    ])->and($dashboard->totalAgencies)->toBe(4);
});

test('the subscription figures count only live subscriptions', function () {
    $platform = seedPlatform();

    $dashboard = adminDashboard()->instance();

    expect($dashboard->liveSubscriptionCounts)->toBe([
        SubscriptionStatus::Trialing->value => 0,
        SubscriptionStatus::Active->value => 1,
        SubscriptionStatus::PastDue->value => 1,
    ])->and($dashboard->livePlans)->toBe(['Agency Plus' => 1, 'Agency Standard' => 1])
        ->and($dashboard->agenciesWithoutSubscription)->toBe(2)
        ->and($dashboard->upcomingRenewals->modelKeys())->toBe([$platform['renewing']->id]);
});

test('the billing figures owe only unsettled invoices and count only money received this month', function () {
    $platform = seedPlatform();

    $dashboard = adminDashboard()->instance();

    expect($dashboard->overdueInvoiceCount)->toBe(1)
        ->and($dashboard->overdueOwed->format())->toBe('₱2,500.00')
        ->and($dashboard->outstandingOwed->format())->toBe('₱4,000.00')
        ->and($dashboard->receivedThisMonth->format())->toBe('₱1,000.00')
        ->and($dashboard->latestPayments->modelKeys())->toBe([$platform['recent']->id, $platform['lastMonth']->id]);
});

test('the people figures split active from deactivated and leave super admins out', function () {
    seedPlatform();

    $dashboard = adminDashboard()->instance();

    expect($dashboard->people)->toBe([
        ['label' => 'Admins', 'active' => 1, 'deactivated' => 0],
        ['label' => 'Managers', 'active' => 1, 'deactivated' => 0],
        ['label' => 'Staff', 'active' => 1, 'deactivated' => 1],
        ['label' => 'Customers', 'active' => 1, 'deactivated' => 0],
    ])->and($dashboard->agenciesWithoutAdmin->modelKeys())->toBe(['bais', 'sibulan', 'tanjay']);
});

test('what needs attention lists pending, past-due and suspended agencies, each linked to its page', function () {
    seedPlatform();

    adminDashboard()
        ->assertSeeInOrder(['Needs attention', 'Waiting to be activated', 'Bais Travel', 'Subscription past due', 'Tanjay Travel', 'Suspended', 'Sibulan Travel', 'Unpaid platform fees.'])
        ->assertSeeHtml('href="'.route('admin.tenants.show', 'bais').'"')
        ->assertSeeHtml('href="'.route('admin.tenants.show', 'tanjay').'"')
        ->assertSeeHtml('href="'.route('admin.tenants.show', 'sibulan').'"');
});

test('each figure links to the page where it is acted on', function () {
    seedPlatform();

    $page = adminDashboard()
        ->assertSeeHtml('href="'.route('admin.tenants.index').'"')
        ->assertSeeHtml('href="'.route('admin.invoices.overdue').'"')
        ->assertSeeHtml('href="'.route('admin.audit.index').'"')
        ->assertSeeHtml('href="'.route('admin.tenants.create').'"');

    foreach (TenantStatus::cases() as $status) {
        $page->assertSeeHtml('href="'.e(route('admin.tenants.index', ['status' => $status->value])).'"');
    }
});

test('recent activity shows the latest platform events', function () {
    seedPlatform();
    app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantSuspended, null, Tenant::find('sibulan'), changes: ['status' => ['old' => 2, 'new' => 4]]);

    adminDashboard()->assertSeeInOrder(['Recent activity', 'Tenant Suspended', 'status: 2 → 4', 'Sibulan Travel', 'System']);
});

test('a platform with no agencies says so and offers onboarding instead of empty figures', function () {
    adminDashboard()
        ->assertSee('No agencies yet')
        ->assertSeeHtml('href="'.route('admin.tenants.create').'"')
        ->assertDontSee('Agencies by status')
        ->assertDontSee('Needs attention');
});

test('the dashboard component refuses non-super-admins without the admin middleware', function () {
    Livewire::actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create())
        ->test('pages::admin.dashboard')
        ->assertForbidden();
});
