<?php

use App\Actions\Subscriptions\StartSubscriptionAction;
use App\Enums\AuditAction;
use App\Enums\BillingInterval;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Subscriptions live in the central database, so the per-tenant database
    // provisioning that TenantCreated triggers is skipped.
    Event::fake([TenantCreated::class]);
});

function subscriptionPage(Tenant $tenant, User $superAdmin): Testable
{
    return Livewire::actingAs($superAdmin)->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('starting an agency\'s first subscription snapshots the chosen plan and records it', function () {
    travelTo('2026-10-10 09:30:00');
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Standard', 'amount_minor' => 250000, 'interval' => BillingInterval::Monthly]);

    subscriptionPage($tenant, $superAdmin)
        ->set('subscription_plan_id', $plan->id)
        ->call('startSubscription')
        ->assertHasNoErrors()
        ->assertSet('subscription_plan_id', null);

    $subscription = Subscription::sole();
    expect($subscription->tenant_id)->toBe($tenant->id)
        ->and($subscription->active_for_tenant_id)->toBe($tenant->id)
        ->and($subscription->subscription_plan_id)->toBe($plan->id)
        ->and($subscription->plan_name)->toBe('Agency Standard')
        ->and($subscription->amount_minor)->toBe(250000)
        ->and($subscription->currency)->toBe('PHP')
        ->and($subscription->interval)->toBe(BillingInterval::Monthly)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->starts_on->toDateString())->toBe('2026-10-10')
        ->and($subscription->current_period_start_on->toDateString())->toBe('2026-10-10')
        ->and($subscription->current_period_end_on->toDateString())->toBe('2026-11-09')
        ->and($subscription->next_billing_on?->toDateString())->toBe('2026-11-10')
        ->and($subscription->created_by)->toBe($superAdmin->id);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::SubscriptionCreated)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('subscription')
        ->and($event->subject_id)->toBe($subscription->id)
        ->and($event->changes)->toBeNull();
});

test('changing plan ends the live subscription and starts the new one in its place', function () {
    travelTo('2026-12-01 09:30:00');
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $previous = Subscription::factory()->for($tenant)->create(['plan_name' => 'Agency Standard', 'amount_minor' => 250000]);

    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Plus', 'amount_minor' => 900000, 'interval' => BillingInterval::Quarterly]);

    $subscription = app(StartSubscriptionAction::class)->handle($superAdmin, $tenant, ['subscription_plan_id' => $plan->id]);

    $previous->refresh();
    expect($previous->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($previous->active_for_tenant_id)->toBeNull()
        ->and($previous->cancelled_at?->toDateTimeString())->toBe('2026-12-01 09:30:00')
        ->and($previous->cancel_reason)->toBe('Replaced by Agency Plus.')
        ->and($previous->ends_on?->toDateString())->toBe('2026-12-01')
        ->and($previous->next_billing_on)->toBeNull();

    expect($subscription->active_for_tenant_id)->toBe($tenant->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->subscriptions()->count())->toBe(2);

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::SubscriptionPlanChanged)
        ->and($event->subject_id)->toBe($subscription->id)
        ->and($event->changes)->toBe([
            'plan_name' => ['old' => 'Agency Standard', 'new' => 'Agency Plus'],
            'amount_minor' => ['old' => 250000, 'new' => 900000],
            'interval' => ['old' => 1, 'new' => 2],
        ]);
});

test('the billing period runs one interval from today, clamped to the end of a shorter month', function (string $today, BillingInterval $interval, string $periodEnd, string $nextBilling) {
    travelTo($today);

    $subscription = app(StartSubscriptionAction::class)->handle(User::factory()->superAdmin()->create(), Tenant::factory()->create(), [
        'subscription_plan_id' => SubscriptionPlan::factory()->create(['interval' => $interval])->id,
    ]);

    expect($subscription->current_period_end_on->toDateString())->toBe($periodEnd)
        ->and($subscription->next_billing_on?->toDateString())->toBe($nextBilling);
})->with([
    'quarterly' => ['2026-10-10', BillingInterval::Quarterly, '2027-01-09', '2027-01-10'],
    'annual' => ['2026-10-10', BillingInterval::Annual, '2027-10-09', '2027-10-10'],
    'monthly from the 31st' => ['2027-01-31', BillingInterval::Monthly, '2027-02-27', '2027-02-28'],
]);

test('a plan that is not in the price list is rejected and changes nothing', function (Closure $planId, string $message) {
    $tenant = Tenant::factory()->create();
    $live = Subscription::factory()->for($tenant)->create();

    subscriptionPage($tenant, User::factory()->superAdmin()->create())
        ->set('subscription_plan_id', $planId())
        ->call('startSubscription')
        ->assertHasErrors(['subscription_plan_id' => $message]);

    expect(Subscription::sole()->is($live))->toBeTrue()
        ->and($live->fresh()?->active_for_tenant_id)->toBe($tenant->id)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'none chosen' => [fn () => null, 'Choose a plan.'],
    'withdrawn' => [fn () => tap(SubscriptionPlan::factory()->create())->delete()->id, 'That plan is no longer offered. Choose another.'],
    'never existed' => [fn () => 999999, 'That plan is no longer offered. Choose another.'],
]);

test('the plan picker offers the price list by name, and points to it while it is empty', function () {
    $tenant = Tenant::factory()->create();
    SubscriptionPlan::factory()->create(['name' => 'Agency Plus', 'amount_minor' => 900000, 'interval' => BillingInterval::Quarterly]);
    SubscriptionPlan::factory()->create(['name' => 'Agency Basic', 'amount_minor' => 180000]);
    tap(SubscriptionPlan::factory()->create(['name' => 'Withdrawn Plan']))->delete();

    subscriptionPage($tenant, User::factory()->superAdmin()->create())
        ->assertSeeInOrder(['Agency Basic · ₱1,800.00 · Monthly', 'Agency Plus · ₱9,000.00 · Quarterly'])
        ->assertDontSee('Withdrawn Plan')
        ->assertDontSee('The price list is empty');

    SubscriptionPlan::query()->delete();

    subscriptionPage($tenant, User::factory()->superAdmin()->create())
        ->assertSee('The price list is empty')
        ->assertSeeHtml('href="'.route('admin.plans.index').'"');
});

test('a plan written by someone else at the same moment is refused cleanly and nothing is half-written', function () {
    $tenant = Tenant::factory()->create();
    $live = Subscription::factory()->for($tenant)->create();
    $raced = false;
    // Another request fills the active slot between ending the live plan and
    // inserting the new one.
    Subscription::creating(function () use ($tenant, &$raced) {
        if (! $raced) {
            $raced = true;
            Subscription::factory()->for($tenant)->create(['plan_name' => 'Concurrent Plan']);
        }
    });

    expect(fn () => app(StartSubscriptionAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, [
        'subscription_plan_id' => SubscriptionPlan::factory()->create()->id,
    ]))->toThrow(ValidationException::class, 'This agency was put on another plan at the same moment. Reload the page to see it.');

    expect(Subscription::sole()->is($live))->toBeTrue()
        ->and($live->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('the history lists only this agency\'s subscriptions, newest first, with their amounts', function () {
    $tenant = Tenant::factory()->create();
    $ended = Subscription::factory()->for($tenant)->expired()->create(['plan_name' => 'Agency Starter', 'amount_minor' => 150000]);
    $live = Subscription::factory()->for($tenant)->create(['plan_name' => 'Agency Standard', 'amount_minor' => 250001]);
    Subscription::factory()->create(['plan_name' => 'Another Agency\'s Plan']);

    $page = subscriptionPage($tenant, User::factory()->superAdmin()->create());

    expect($page->instance()->subscriptions->modelKeys())->toBe([$live->id, $ended->id]);
    $page->assertSeeInOrder(['Subscription history', 'Agency Standard', '₱2,500.01', 'Agency Starter', '₱1,500.00'])
        ->assertDontSee('Another Agency&#039;s Plan', escape: false);
});

test('the plan button reads as a change only while the agency has a live subscription', function (?Closure $subscribe, string $label) {
    $tenant = Tenant::factory()->create();
    $subscribe?->__invoke($tenant);

    subscriptionPage($tenant, User::factory()->superAdmin()->create())->assertSee($label);
})->with([
    'never subscribed' => [null, 'Start subscription'],
    'ended' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->expired()->create(), 'Start subscription'],
    'live' => [fn (Tenant $tenant) => Subscription::factory()->for($tenant)->create(), 'Change plan'],
]);

test('starting a subscription is refused once the user is no longer a super admin', function () {
    $tenant = Tenant::factory()->create();
    $page = subscriptionPage($tenant, User::factory()->superAdmin()->create());
    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create());

    $page->set('subscription_plan_id', SubscriptionPlan::factory()->create()->id)
        ->call('startSubscription')
        ->assertForbidden();

    expect(Subscription::count())->toBe(0);
});
