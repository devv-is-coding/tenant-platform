<?php

use App\Actions\Subscriptions\DecidePlanChangeRequestAction;
use App\Enums\AuditAction;
use App\Enums\PlanChangeRequestStatus;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\PlanChangeRequest;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Plan requests, plans and subscriptions are central, so no tenant
    // database is provisioned.
    Event::fake([TenantCreated::class]);
});

function planRequestsPage(?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())->test('pages::admin.plan-requests.index');
}

test('the page lists waiting requests oldest first, then the recently decided', function () {
    $first = PlanChangeRequest::factory()->for(Tenant::factory()->state(['name' => 'Dumaguete Travel']))->create(['reason' => 'first-note']);
    $second = PlanChangeRequest::factory()->for(Tenant::factory()->state(['name' => 'Tanjay Travel']))->create(['reason' => 'second-note']);
    PlanChangeRequest::factory()->declined()->create(['decision_reason' => 'declined-answer']);

    $page = planRequestsPage();

    expect($page->instance()->pending->pluck('id')->all())->toBe([$first->id, $second->id]);
    $page->assertSeeInOrder(['Waiting for a decision', 'Dumaguete Travel', 'first-note', 'Tanjay Travel', 'second-note', 'Recently decided', 'Declined', 'declined-answer']);
});

test('approving puts the agency on the plan, ends its old one, closes the request, and is audited once', function () {
    $tenant = Tenant::factory()->create();
    $old = Subscription::factory()->for($tenant)->create(['plan_name' => 'Old Basic']);
    $plan = SubscriptionPlan::factory()->create(['name' => 'Agency Advanced Monthly', 'tier' => PlanTier::Advanced]);
    $request = PlanChangeRequest::factory()->for($tenant)->for($plan, 'plan')->create();
    $superAdmin = User::factory()->superAdmin()->create();

    planRequestsPage($superAdmin)
        ->call('confirmApprove', $request->id)
        ->call('approve')
        ->assertHasNoErrors();

    $live = $tenant->liveSubscription()->sole();

    expect($live->subscription_plan_id)->toBe($plan->id)
        ->and($live->tier)->toBe(PlanTier::Advanced)
        ->and($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($request->fresh()->status)->toBe(PlanChangeRequestStatus::Approved)
        ->and($request->fresh()->pending_for_tenant_id)->toBeNull()
        ->and($request->fresh()->decided_by)->toBe($superAdmin->id)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::PlanChangeApproved)->count())->toBe(1)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionPlanChanged)->count())->toBe(1);
});

test('a plan withdrawn from the price list after the request was sent cannot be approved', function () {
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::factory()->create();
    $request = PlanChangeRequest::factory()->for($tenant)->for($plan, 'plan')->create();
    $plan->delete();

    planRequestsPage()
        ->call('confirmApprove', $request->id)
        ->call('approve')
        ->assertHasErrors(['decision' => 'This plan was withdrawn from the price list after the request was sent. Decline the request, or agree another plan with the agency\'s admin.']);

    expect($request->fresh()->status)->toBe(PlanChangeRequestStatus::Pending)
        ->and($tenant->liveSubscription()->exists())->toBeFalse();
});

test('declining needs a reason, leaves the plan as it was, and is audited', function () {
    $tenant = Tenant::factory()->create();
    $request = PlanChangeRequest::factory()->for($tenant)->create();

    planRequestsPage()
        ->call('confirmDecline', $request->id)
        ->call('decline')
        ->assertHasErrors(['decision_reason' => 'Say why the request is declined.']);

    expect($request->fresh()->status)->toBe(PlanChangeRequestStatus::Pending);

    planRequestsPage()
        ->call('confirmDecline', $request->id)
        ->set('decision_reason', 'Pro needs a signed contract first.')
        ->call('decline')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(PlanChangeRequestStatus::Declined)
        ->and($request->fresh()->decision_reason)->toBe('Pro needs a signed contract first.')
        ->and($request->fresh()->pending_for_tenant_id)->toBeNull()
        ->and($tenant->liveSubscription()->exists())->toBeFalse()
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::PlanChangeDeclined)->sole()->description)->toBe('Pro needs a signed contract first.');
});

test('a closed request cannot be decided again', function (string $state) {
    $request = PlanChangeRequest::factory()->{$state}()->create();
    $decide = app(DecidePlanChangeRequestAction::class);
    $superAdmin = User::factory()->superAdmin()->create();

    expect(fn () => $decide->approve($superAdmin, $request))->toThrow(ValidationException::class)
        ->and(fn () => $decide->decline($superAdmin, $request, ['decision_reason' => 'No.']))->toThrow(ValidationException::class);
})->with(['declined', 'approved', 'withdrawn']);

test('an agency\'s admin can neither list nor decide plan requests, even their own', function () {
    $request = PlanChangeRequest::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($request->tenant)->create();

    actingAs($admin)->get(route('admin.plan-requests.index'))->assertForbidden();

    expect(fn () => app(DecidePlanChangeRequestAction::class)->approve($admin, $request))->toThrow(AuthorizationException::class);
});

test('the page component refuses non-super-admins without the console middleware', function () {
    Livewire::actingAs(User::factory()->role(Role::Admin)->for(Tenant::factory())->create())
        ->test('pages::admin.plan-requests.index')
        ->assertForbidden();
});

test('the dashboard counts waiting plan requests under Needs attention', function () {
    PlanChangeRequest::factory()->count(2)->create();
    PlanChangeRequest::factory()->declined()->create();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.dashboard')
        ->assertSeeInOrder(['Needs attention', 'Plan requests', '2 requests waiting']);
});

test('the agency\'s page shows the plan its admin is waiting for', function () {
    $tenant = Tenant::factory()->create();
    PlanChangeRequest::factory()->for($tenant)->for(SubscriptionPlan::factory()->state(['name' => 'Agency Pro Annual']), 'plan')->create();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->assertSeeHtml('data-test="pending-plan-request"')
        ->assertSee('The agency\'s admin asked for Agency Pro Annual.');
});

test('text the agency wrote is escaped when shown', function () {
    PlanChangeRequest::factory()->create(['reason' => '<script>alert(1)</script>']);

    planRequestsPage()
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});
