<?php

use App\Enums\AuditAction;
use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Plans and subscriptions live in the central database, so the per-tenant
    // database provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function plansPage(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->superAdmin()->create())->test('pages::admin.plans.index');
}

test('a super admin adds a plan priced in the billing currency, and it is recorded', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    plansPage($superAdmin)
        ->call('createPlan')
        ->set(['name' => ' Agency Plus ', 'tier' => PlanTier::Advanced->value, 'amount' => '9000.50', 'interval' => BillingInterval::Quarterly->value])
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSee('Agency Plus');

    $plan = SubscriptionPlan::sole();
    expect($plan->name)->toBe('Agency Plus')
        ->and($plan->tier)->toBe(PlanTier::Advanced)
        ->and($plan->amount_minor)->toBe(900050)
        ->and($plan->currency)->toBe('PHP')
        ->and($plan->interval)->toBe(BillingInterval::Quarterly)
        ->and($plan->created_by)->toBe($superAdmin->id);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::PlanCreated)
        ->and($event->tenant_id)->toBeNull()
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('subscription_plan')
        ->and($event->subject_id)->toBe($plan->id);
});

test('repricing or re-tiering a plan changes the price list but not what agencies already on it pay or get', function () {
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Standard', 'tier' => PlanTier::Advanced, 'amount_minor' => 250000, 'interval' => BillingInterval::Monthly]);
    $agreed = Subscription::factory()->create(['subscription_plan_id' => $plan->id, 'plan_name' => 'Agency Standard', 'tier' => PlanTier::Advanced, 'amount_minor' => 250000]);

    plansPage()
        ->call('editPlan', $plan->id)
        ->assertSet('name', 'Agency Standard')
        ->assertSet('tier', PlanTier::Advanced->value)
        ->assertSet('amount', '2500.00')
        ->assertSet('interval', BillingInterval::Monthly->value)
        ->set(['name' => 'Agency Standard 2027', 'tier' => PlanTier::Pro->value, 'amount' => '2750'])
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($plan->fresh()?->name)->toBe('Agency Standard 2027')
        ->and($plan->fresh()?->tier)->toBe(PlanTier::Pro)
        ->and($plan->fresh()?->amount_minor)->toBe(275000)
        ->and(SubscriptionPlan::count())->toBe(1)
        ->and($agreed->fresh()?->plan_name)->toBe('Agency Standard')
        ->and($agreed->fresh()?->tier)->toBe(PlanTier::Advanced)
        ->and($agreed->fresh()?->amount_minor)->toBe(250000);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::PlanUpdated)
        ->and($event->subject_id)->toBe($plan->id)
        ->and($event->changes)->toBe([
            'name' => ['old' => 'Agency Standard', 'new' => 'Agency Standard 2027'],
            'tier' => ['old' => PlanTier::Advanced->value, 'new' => PlanTier::Pro->value],
            'amount_minor' => ['old' => 250000, 'new' => 275000],
        ]);
});

test('saving a plan without changing it records nothing', function () {
    $plan = SubscriptionPlan::factory()->create();

    plansPage()->call('editPlan', $plan->id)->call('savePlan')->assertHasNoErrors();

    expect(PlatformAuditEvent::count())->toBe(0);
});

test('an invalid plan is rejected and nothing is saved', function (array $input, string $field, string $message) {
    SubscriptionPlan::factory()->create(['name' => 'Agency Standard']);

    plansPage()
        ->call('createPlan')
        ->set([...['name' => 'Agency Plus', 'amount' => '9000', 'interval' => BillingInterval::Monthly->value], ...$input])
        ->call('savePlan')
        ->assertHasErrors([$field => $message]);

    expect(SubscriptionPlan::count())->toBe(1)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name', 'The name field is required.'],
    'a name already in the price list' => [['name' => 'Agency Standard'], 'name', 'Another plan already has this name.'],
    'zero' => [['amount' => '0.00'], 'amount', 'The amount must be more than zero.'],
    'three decimal places' => [['amount' => '1.234'], 'amount', 'Enter the amount as a number with at most two decimal places, without commas.'],
    'thousands separator' => [['amount' => '2,500'], 'amount', 'Enter the amount as a number with at most two decimal places, without commas.'],
    'unknown interval' => [['interval' => 9], 'interval', 'The selected interval is invalid.'],
    'unknown tier' => [['tier' => 9], 'tier', 'The selected tier is invalid.'],
]);

test('a deleted plan\'s name can be offered again', function () {
    tap(SubscriptionPlan::factory()->create(['name' => 'Agency Standard']))->delete();

    plansPage()
        ->call('createPlan')
        ->set(['name' => 'Agency Standard', 'amount' => '2500', 'interval' => BillingInterval::Monthly->value])
        ->call('savePlan')
        ->assertHasNoErrors();

    expect(SubscriptionPlan::query()->where('name', 'Agency Standard')->count())->toBe(1);
});

test('deleting a plan withdraws it from the price list, with a reason, and leaves agencies on it billing', function () {
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Starter']);
    $live = Subscription::factory()->create(['subscription_plan_id' => $plan->id]);

    plansPage()
        ->call('confirmDelete', $plan->id)
        ->set('delete_reason', 'Folded into Agency Standard.')
        ->call('deletePlan')
        ->assertHasNoErrors()
        ->assertDontSee('Agency Starter');

    expect(SubscriptionPlan::find($plan->id))->toBeNull()
        ->and(SubscriptionPlan::withTrashed()->find($plan->id)?->deleted_at)->not->toBeNull()
        ->and($live->fresh()?->active_for_tenant_id)->toBe($live->tenant_id);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::PlanDeleted)
        ->and($event->subject_type)->toBe('subscription_plan')
        ->and($event->subject_id)->toBe($plan->id)
        ->and($event->description)->toBe('Folded into Agency Standard.');
});

test('a plan is not deleted without a reason', function () {
    $plan = SubscriptionPlan::factory()->create();

    plansPage()
        ->call('confirmDelete', $plan->id)
        ->call('deletePlan')
        ->assertHasErrors(['delete_reason' => 'Say why this is being deleted. The reason is kept in the audit log.']);

    expect($plan->fresh()?->trashed())->toBeFalse()
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('the price list shows each plan under its tier, by name, with its price, interval and live agencies', function () {
    $standard = SubscriptionPlan::factory()->create(['name' => 'Agency Standard', 'tier' => PlanTier::Basic, 'amount_minor' => 250000, 'interval' => BillingInterval::Monthly]);
    SubscriptionPlan::factory()->create(['name' => 'Agency Annual', 'tier' => PlanTier::Pro, 'amount_minor' => 2500000, 'interval' => BillingInterval::Annual]);
    SubscriptionPlan::factory()->create(['name' => 'Agency Basic', 'tier' => PlanTier::Basic, 'amount_minor' => 180000, 'interval' => BillingInterval::Monthly]);
    Subscription::factory()->count(2)->create(['subscription_plan_id' => $standard->id]);
    Subscription::factory()->expired()->create(['subscription_plan_id' => $standard->id]);

    $page = plansPage();

    expect($page->instance()->plans->firstWhere('id', $standard->id)?->live_subscriptions_count)->toBe(2);
    $page->assertSeeInOrder([
        'Run the agency', 'Agency Basic', '₱1,800.00', 'Agency Standard', '₱2,500.00', 'Monthly',
        'Sell online, run tours smoothly', 'No Advanced plan yet.',
        'The luxury layer', 'Agency Annual', '₱25,000.00', 'Annual',
    ]);
});

test('the price list starts empty, with nothing invented', function () {
    plansPage()->assertSee('No plans yet. Add one to start putting agencies on it.');

    expect(SubscriptionPlan::count())->toBe(0);
});

test('every write on the price list is refused once the user is no longer a super admin', function (string $action, Closure $arguments) {
    $plan = SubscriptionPlan::factory()->create(['amount_minor' => 250000]);
    // The page is opened by a super admin and the session then changes hands,
    // as in a stale tab: each action must authorize for itself.
    $page = plansPage()->set(['name' => 'Hijacked', 'amount' => '1', 'delete_reason' => 'Because.']);
    actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create());

    $page->call($action, ...$arguments($plan))->assertForbidden();

    expect($plan->fresh()?->amount_minor)->toBe(250000)
        ->and($plan->fresh()?->trashed())->toBeFalse()
        ->and(SubscriptionPlan::count())->toBe(1)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'opening a new plan' => ['createPlan', fn () => []],
    'adding a plan' => ['savePlan', fn () => []],
    'opening a plan to edit' => ['editPlan', fn (SubscriptionPlan $plan) => [$plan->id]],
    'opening the delete confirmation' => ['confirmDelete', fn (SubscriptionPlan $plan) => [$plan->id]],
]);
