<?php

use App\Actions\Subscriptions\SchedulePlanChangeAction;
use App\Enums\AuditAction;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Plans and subscriptions are central, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

/**
 * The agency on an Advanced plan that renews on 1 December 2026, with its admin.
 *
 * @return array{0: Tenant, 1: Subscription, 2: User}
 */
function onAdvancedRenewingInDecember(): array
{
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->for($tenant)->create([
        'plan_name' => 'Agency Advanced Monthly',
        'tier' => PlanTier::Advanced,
        'current_period_start_on' => '2026-11-01',
        'current_period_end_on' => '2026-11-30',
        'next_billing_on' => '2026-12-01',
    ]);

    return [$tenant, $subscription, User::factory()->role(Role::Admin)->for($tenant)->create()];
}

test('a move down waits for the paid period to end, and is recorded', function () {
    travelTo('2026-11-21 10:00:00');
    [$tenant, $subscription, $admin] = onAdvancedRenewingInDecember();
    $basic = SubscriptionPlan::factory()->create(['name' => 'Agency Basic Monthly', 'tier' => PlanTier::Basic]);

    app(SchedulePlanChangeAction::class)->schedule($admin, $tenant, $basic);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionChangeScheduled)->sole();
    expect($subscription->fresh()?->only(['scheduled_plan_id', 'scheduled_by', 'tier']))
        ->toBe(['scheduled_plan_id' => $basic->id, 'scheduled_by' => $admin->id, 'tier' => PlanTier::Advanced])
        ->and([$event->actor_id, $event->description])->toBe([$admin->id, 'Moves to Agency Basic Monthly on Dec 1, 2026.']);
});

test('a move up, the current plan, or a withdrawn plan cannot be scheduled', function (Closure $makePlan, string $message) {
    [$tenant, $subscription, $admin] = onAdvancedRenewingInDecember();
    $plan = $makePlan($subscription);

    expect(fn (): Subscription => app(SchedulePlanChangeAction::class)->schedule($admin, $tenant, $plan))
        ->toThrow(ValidationException::class, $message);

    expect($subscription->fresh()?->scheduled_plan_id)->toBeNull();
})->with([
    'a move up' => [
        fn (): SubscriptionPlan => SubscriptionPlan::factory()->create(['name' => 'Agency Pro Monthly', 'tier' => PlanTier::Pro]),
        'Agency Pro Monthly is a move up: pay for it, and it starts at once.',
    ],
    'the current plan' => [
        function (Subscription $subscription): SubscriptionPlan {
            $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Advanced Monthly', 'tier' => PlanTier::Advanced]);
            $subscription->update(['subscription_plan_id' => $plan->id]);

            return $plan;
        },
        'The agency is already on Agency Advanced Monthly.',
    ],
    'a withdrawn plan' => [
        fn (): SubscriptionPlan => tap(SubscriptionPlan::factory()->create(['tier' => PlanTier::Basic]))->delete(),
        'That plan is no longer offered. Choose another.',
    ],
]);

test('an agency with no plan has nothing to schedule a move from', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    expect(fn (): Subscription => app(SchedulePlanChangeAction::class)->schedule($admin, $tenant, SubscriptionPlan::factory()->create()))
        ->toThrow(ValidationException::class, 'The agency has no plan to move from. Buy one instead.');
});

test('keeping the current plan drops the waiting move, and is recorded', function () {
    [$tenant, $subscription, $admin] = onAdvancedRenewingInDecember();
    $basic = SubscriptionPlan::factory()->create(['name' => 'Agency Basic Monthly', 'tier' => PlanTier::Basic]);
    app(SchedulePlanChangeAction::class)->schedule($admin, $tenant, $basic);

    app(SchedulePlanChangeAction::class)->unschedule($admin, $tenant);

    expect($subscription->fresh()?->only(['scheduled_plan_id', 'scheduled_by']))->toBe(['scheduled_plan_id' => null, 'scheduled_by' => null])
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionChangeUnscheduled)->sole()->description)
        ->toBe('Stays on Agency Advanced Monthly; the move to Agency Basic Monthly is off.');
});

test('a move to a plan withdrawn since it was scheduled is dropped on its day, and the agency stays on its plan', function () {
    [$tenant, $subscription, $admin] = onAdvancedRenewingInDecember();
    $basic = SubscriptionPlan::factory()->create(['tier' => PlanTier::Basic]);
    app(SchedulePlanChangeAction::class)->schedule($admin, $tenant, $basic);
    $basic->delete();
    travelTo('2026-12-01 06:00:00');

    $replacement = app(SchedulePlanChangeAction::class)->applyIfDue($subscription->fresh() ?? $subscription, today());

    expect($replacement)->toBeNull()
        ->and($tenant->liveSubscription()->sole()->id)->toBe($subscription->id)
        ->and($subscription->fresh()?->scheduled_plan_id)->toBeNull();
});
