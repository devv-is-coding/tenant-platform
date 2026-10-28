<?php

use App\Actions\Subscriptions\RequestPlanChangeAction;
use App\Enums\AuditAction;
use App\Enums\PlanChangeRequestStatus;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\PlanChangeRequest;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The agency's side of plan requests, on its Plan & billing page (TA-25):
 * ask, see what would change, withdraw, and see the outcome. The super
 * admin's side is in Admin/PlanChangeRequestsTest.
 */

/**
 * Open the agency's plan & billing page as the user, from within the agency.
 */
function agencyPlanPage(Tenant $tenant, User $user): Testable
{
    tenancy()->initialize($tenant);

    return Livewire::actingAs($user)->test('pages::tenant.billing');
}

/**
 * Ask for the plan from the agency's billing page, as the admin.
 */
function askForPlan(Tenant $tenant, User $admin, SubscriptionPlan $plan, string $reason = ''): Testable
{
    return agencyPlanPage($tenant, $admin)
        ->call('openPlanRequest')
        ->set('subscription_plan_id', (string) $plan->id)
        ->set('reason', $reason)
        ->call('sendPlanRequest');
}

test('the admin asks for a plan with a note; it waits for the platform, and is recorded', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Advanced Monthly', 'tier' => PlanTier::Advanced]);

    askForPlan($tenant, $admin, $plan, 'We start selling Japan tours next month.')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="pending-plan-request"')
        ->assertSee('You asked for Agency Advanced Monthly.')
        ->assertDontSeeHtml('data-test="ask-for-plan-button"');

    $request = PlanChangeRequest::sole();
    $event = PlatformAuditEvent::sole();

    expect($request->tenant_id)->toBe($tenant->id)
        ->and($request->subscription_plan_id)->toBe($plan->id)
        ->and($request->requested_by)->toBe($admin->id)
        ->and($request->reason)->toBe('We start selling Japan tours next month.')
        ->and($request->status)->toBe(PlanChangeRequestStatus::Pending)
        ->and($event->action)->toBe(AuditAction::PlanChangeRequested)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('plan_change_request');
});

test('choosing a plan shows the features the agency would gain and the ones it would lose', function () {
    $tenant = tenantOnDomain(tier: PlanTier::Advanced);
    $basic = SubscriptionPlan::factory()->create(['tier' => PlanTier::Basic]);
    $pro = SubscriptionPlan::factory()->create(['tier' => PlanTier::Pro]);

    $page = agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->call('openPlanRequest')
        ->set('subscription_plan_id', (string) $basic->id);

    expect($page->instance()->planChange['adds'])->toBe([])
        ->and($page->instance()->planChange['removes'])->toContain(PlanFeature::Reviews)
        ->not->toContain(PlanFeature::Team);
    $page->assertSee('You would lose');

    $page->set('subscription_plan_id', (string) $pro->id);

    expect($page->instance()->planChange['removes'])->toBe([])
        ->and($page->instance()->planChange['adds'])->not->toBe([])
        ->not->toContain(PlanFeature::Reviews);
});

test('the request must name a plan still in the price list', function (Closure $makePlanId, string $message) {
    $tenant = tenantOnDomain();

    agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->set('subscription_plan_id', $makePlanId())
        ->call('sendPlanRequest')
        ->assertHasErrors(['subscription_plan_id' => $message]);

    expect(PlanChangeRequest::count())->toBe(0);
})->with([
    'no plan chosen' => [fn () => '', 'Choose a plan.'],
    'a plan withdrawn from the price list' => [fn () => (string) tap(SubscriptionPlan::factory()->create())->delete()->id, 'That plan is no longer offered. Choose another.'],
]);

test('the agency cannot ask for the plan it is already on', function () {
    $tenant = tenantOnDomain();
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Basic Monthly']);
    Subscription::factory()->for($tenant)->create(['subscription_plan_id' => $plan->id]);

    agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->set('subscription_plan_id', (string) $plan->id)
        ->call('sendPlanRequest')
        ->assertHasErrors(['subscription_plan_id' => 'The agency is already on Agency Basic Monthly.']);
});

test('a second request is refused while one waits', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    PlanChangeRequest::factory()->for($tenant)->create(['requested_by' => $admin->id]);

    agencyPlanPage($tenant, $admin)
        ->set('subscription_plan_id', (string) SubscriptionPlan::factory()->create()->id)
        ->call('sendPlanRequest')
        ->assertHasErrors(['subscription_plan_id' => 'The agency already has a plan request waiting. Withdraw it to ask for another plan.']);

    expect(PlanChangeRequest::count())->toBe(1);
});

test('the admin withdraws the waiting request, which frees the agency to ask again', function () {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $request = PlanChangeRequest::factory()->for($tenant)->create(['requested_by' => $admin->id]);

    agencyPlanPage($tenant, $admin)
        ->call('withdrawPlanRequest')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="pending-plan-request"')
        ->assertSeeInOrder(['Plan requests', 'Withdrawn']);

    expect($request->fresh()->status)->toBe(PlanChangeRequestStatus::Withdrawn)
        ->and($request->fresh()->pending_for_tenant_id)->toBeNull()
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::PlanChangeWithdrawn)->count())->toBe(1);

    askForPlan($tenant, $admin, SubscriptionPlan::factory()->create())->assertHasNoErrors();
});

test('the admin sees each closed request\'s outcome, a declined one with the platform\'s reason, never another agency\'s', function () {
    $tenant = tenantOnDomain();
    PlanChangeRequest::factory()->for($tenant)->for(SubscriptionPlan::factory()->state(['name' => 'Asked Pro']), 'plan')->declined()->create(['decision_reason' => 'Pro needs a signed contract first.']);
    PlanChangeRequest::factory()->for($tenant)->for(SubscriptionPlan::factory()->state(['name' => 'Asked Advanced']), 'plan')->approved()->create();
    // The price list is shared, so another agency's plan may be offered here;
    // its request, with its note and the platform's answer, never is.
    PlanChangeRequest::factory()->for(tenantOnDomain())->declined()->create(['reason' => 'their-note', 'decision_reason' => 'their-answer']);

    agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('Asked Pro')
        ->assertSee('Reason: Pro needs a signed contract first.')
        ->assertSeeInOrder(['Asked Advanced', 'Approved'])
        ->assertDontSee('their-note')
        ->assertDontSee('their-answer');
});

test('a closed agency with no plan can still ask for one', function () {
    $tenant = tenantOnDomain(TenantStatus::Closed);

    askForPlan($tenant, User::factory()->role(Role::Admin)->for($tenant)->create(), SubscriptionPlan::factory()->create())
        ->assertHasNoErrors();

    expect(PlanChangeRequest::count())->toBe(1);
});

test('only the agency\'s own admin can ask, however the action is reached', function (Closure $makeUser) {
    $tenant = tenantOnDomain();

    expect(fn () => app(RequestPlanChangeAction::class)->handle($makeUser($tenant), $tenant, ['subscription_plan_id' => SubscriptionPlan::factory()->create()->id]))
        ->toThrow(AuthorizationException::class);

    expect(PlanChangeRequest::count())->toBe(0);
})->with([
    'its manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(tenantOnDomain())->create()],
]);

test('the note and the platform\'s reason are escaped when shown', function () {
    $tenant = tenantOnDomain();
    PlanChangeRequest::factory()->for($tenant)->declined()->create(['reason' => '<script>alert(1)</script>', 'decision_reason' => '<b>no</b>']);

    agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertDontSee('<b>no</b>', escape: false);
});

test('with no other plan in the price list there is nothing to ask for, and the page says so', function () {
    $tenant = tenantOnDomain();
    $plan = SubscriptionPlan::factory()->create();
    Subscription::factory()->for($tenant)->create(['subscription_plan_id' => $plan->id]);

    agencyPlanPage($tenant, User::factory()->role(Role::Admin)->for($tenant)->create())
        ->assertDontSeeHtml('data-test="ask-for-plan-button"')
        ->assertSee('The platform has no other plans on offer right now.');
});
