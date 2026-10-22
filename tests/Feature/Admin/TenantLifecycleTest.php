<?php

use App\Actions\Tenants\ChangeTenantStatusAction;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\freezeSecond;

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // A tenant with billing history cannot be force-deleted, so clear that first.
    Subscription::withTrashed()->forceDelete();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

function lifecyclePage(Tenant $tenant, User $superAdmin): Testable
{
    return Livewire::actingAs($superAdmin)->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

/**
 * @return Collection<int, AuditEvent>
 */
function tenantAuditLog(Tenant $tenant): Collection
{
    return $tenant->run(fn () => AuditEvent::all());
}

test('suspending an agency stores the reason and writes the change to both audit logs', function () {
    freezeSecond();
    $superAdmin = User::factory()->superAdmin()->create(['first_name' => 'Marisol', 'middle_name' => null, 'last_name' => 'Vega']);
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete City Travel & Tours']);

    lifecyclePage($tenant, $superAdmin)
        ->set('status_reason', 'Subscription unpaid 14 days past due.')
        ->call('changeStatus', TenantStatus::Suspended->value)
        ->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->status_reason)->toBe('Subscription unpaid 14 days past due.')
        ->and($tenant->status_changed_at)->toEqual(now())
        ->and($tenant->status_changed_by)->toBe($superAdmin->id);

    $central = PlatformAuditEvent::sole();
    expect($central->action)->toBe(AuditAction::TenantSuspended)
        ->and($central->tenant_id)->toBe($tenant->id)
        ->and($central->tenant_label)->toBe('Dumaguete City Travel & Tours')
        ->and($central->actor_id)->toBe($superAdmin->id)
        ->and($central->actor_role)->toBe(Role::SuperAdmin)
        ->and($central->description)->toBe('Subscription unpaid 14 days past due.')
        ->and($central->changes)->toBe(['status' => ['old' => 2, 'new' => 4]]);

    $notice = tenantAuditLog($tenant)->sole();
    expect($notice->action)->toBe(AuditAction::TenantSuspended)
        ->and($notice->actor_id)->toBe($superAdmin->id)
        ->and($notice->actor_role)->toBe(Role::SuperAdmin)
        ->and($notice->actor_label)->toBe('Marisol Vega (Super admin)')
        ->and($notice->subject_type)->toBe('tenant')
        ->and($notice->description)->toBe('Subscription unpaid 14 days past due.')
        ->and($notice->changes)->toBe(['status' => ['old' => 2, 'new' => 4]]);
});

/*
 * The platform decides whether an agency operates, never how: only lifecycle
 * actions cross into an agency's log, which AuditRecorderTest pins down for
 * item.approved and the other operational actions.
 */
test('a suspension is visible in the platform audit log and in the agency\'s own log', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete City Travel & Tours']);

    app(ChangeTenantStatusAction::class)->handle($superAdmin, $tenant, TenantStatus::Suspended, ['status_reason' => 'Subscription unpaid 14 days past due.']);

    $central = Livewire::actingAs($superAdmin)
        ->test('pages::admin.audit.index')
        ->set('tenant', $tenant->id)
        ->assertSee('Subscription unpaid 14 days past due.')
        ->instance()->events;
    expect($central->pluck('action')->all())->toBe([AuditAction::TenantSuspended]);

    expect(tenantAuditLog($tenant)->pluck('action')->all())->toBe([AuditAction::TenantSuspended]);
});

test('a suspension without a reason is rejected and changes nothing', function (string $reason) {
    $tenant = Tenant::factory()->create();

    lifecyclePage($tenant, User::factory()->superAdmin()->create())
        ->set('status_reason', $reason)
        ->call('changeStatus', TenantStatus::Suspended->value)
        ->assertHasErrors(['status_reason' => 'Say why the agency is being suspended. The agency will see this reason.']);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(PlatformAuditEvent::count())->toBe(0)
        ->and(tenantAuditLog($tenant))->toBeEmpty();
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

test('reactivating restores the agency and its staff exactly as they were before the suspension', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);
    User::factory()->role(Role::Admin)->for($tenant)->create();
    User::factory()->role(Role::Staff)->for($tenant)->inactive()->create();
    $staffBefore = $tenant->users()->orderBy('id')->get()->map->only(['id', 'role', 'is_active', 'tenant_id', 'deleted_at'])->all();
    $changeStatus = app(ChangeTenantStatusAction::class);

    $changeStatus->handle($superAdmin, $tenant, TenantStatus::Suspended, ['status_reason' => 'Unpaid.']);
    $tenant = $changeStatus->handle($superAdmin, $tenant, TenantStatus::Active, ['status_reason' => '']);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->status_reason)->toBeNull()
        ->and($tenant->domains()->pluck('domain')->all())->toBe(["{$tenant->id}.localhost"])
        ->and($tenant->users()->orderBy('id')->get()->map->only(['id', 'role', 'is_active', 'tenant_id', 'deleted_at'])->all())->toBe($staffBefore)
        ->and(PlatformAuditEvent::orderBy('id')->pluck('action')->all())->toBe([AuditAction::TenantSuspended, AuditAction::TenantReactivated])
        ->and(tenantAuditLog($tenant)->sortBy('id')->pluck('action')->all())->toBe([AuditAction::TenantSuspended, AuditAction::TenantReactivated]);
});

test('activating a pending agency takes it live and is recorded as an activation', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Pending]);

    lifecyclePage($tenant, $superAdmin)
        ->call('changeStatus', TenantStatus::Active->value)
        ->assertHasNoErrors();

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(PlatformAuditEvent::sole()->action)->toBe(AuditAction::TenantActivated)
        ->and(tenantAuditLog($tenant)->sole()->action)->toBe(AuditAction::TenantActivated);
});

test('a change the agency\'s current status does not allow is rejected', function (TenantStatus $from, TenantStatus $to, string $message) {
    $tenant = Tenant::factory()->create(['status' => $from]);
    // A valid requester, so a refused pause is refused for its transition.
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    expect(fn () => app(ChangeTenantStatusAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, $to, ['status_reason' => 'A reason.', 'requested_by' => $admin->id]))
        ->toThrow(ValidationException::class, $message);

    expect($tenant->fresh()?->status)->toBe($from)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'suspend a pending agency' => [TenantStatus::Pending, TenantStatus::Suspended, 'The agency is pending, so it cannot be made suspended.'],
    'suspend it twice' => [TenantStatus::Suspended, TenantStatus::Suspended, 'The agency is suspended, so it cannot be made suspended.'],
    'activate an active agency' => [TenantStatus::Active, TenantStatus::Active, 'The agency is active, so it cannot be made active.'],
    'send it back to pending' => [TenantStatus::Active, TenantStatus::Pending, 'The agency is active, so it cannot be made pending.'],
    'pause a pending agency' => [TenantStatus::Pending, TenantStatus::Paused, 'The agency is pending, so it cannot be made paused.'],
    'close a pending agency' => [TenantStatus::Pending, TenantStatus::Closed, 'The agency is pending, so it cannot be made closed.'],
    'pause a suspended agency' => [TenantStatus::Suspended, TenantStatus::Paused, 'The agency is suspended, so it cannot be made paused.'],
    'suspend a closed agency' => [TenantStatus::Closed, TenantStatus::Suspended, 'The agency is closed, so it cannot be made suspended.'],
]);

test('a failed write to the agency\'s audit log undoes the status change and the central row', function () {
    // No TenantCreated, so no tenant database exists for the notice to land in.
    Event::fake([TenantCreated::class]);
    $tenant = Tenant::factory()->create();

    expect(fn () => app(ChangeTenantStatusAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, TenantStatus::Suspended, ['status_reason' => 'Unpaid.']))
        ->toThrow(Exception::class);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('the detail page offers only the changes the agency\'s status allows', function (TenantStatus $status, array $targets, array $labels) {
    $page = lifecyclePage(Tenant::factory()->create(['status' => $status]), User::factory()->superAdmin()->create());

    $actions = collect($page->instance()->statusActions);
    expect($actions->pluck('status')->all())->toBe($targets)
        ->and($actions->pluck('label')->all())->toBe($labels);

    foreach ($targets as $target) {
        $page->assertSeeHtml("change-status-{$target->value}");
    }
})->with([
    'pending' => [TenantStatus::Pending, [TenantStatus::Active], ['Activate']],
    'active' => [TenantStatus::Active, [TenantStatus::Paused, TenantStatus::Suspended, TenantStatus::Closed], ['Pause', 'Suspend', 'Close']],
    'paused' => [TenantStatus::Paused, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Closed], ['Reactivate', 'Suspend', 'Close']],
    'suspended' => [TenantStatus::Suspended, [TenantStatus::Active, TenantStatus::Closed], ['Reactivate', 'Close']],
    'closed' => [TenantStatus::Closed, [TenantStatus::Active], ['Reopen']],
]);

test('pausing records the agency admin who asked, with the reason, in both logs', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    lifecyclePage($tenant, $superAdmin)
        ->set('status_reason', 'Agency-requested seasonal hiatus until 2027-05-01.')
        ->set('requested_by', $admin->id)
        ->call('changeStatus', TenantStatus::Paused->value)
        ->assertHasNoErrors();

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Paused);

    $central = PlatformAuditEvent::sole();
    expect($central->action)->toBe(AuditAction::TenantPaused)
        ->and($central->description)->toBe('Agency-requested seasonal hiatus until 2027-05-01.')
        ->and($central->context)->toBe(['requested_by' => $admin->id]);

    expect(tenantAuditLog($tenant)->sole()->action)->toBe(AuditAction::TenantPaused);
});

test('a pause must name one of the agency\'s own active admins', function (Closure $requester, string $message) {
    $tenant = Tenant::factory()->create();

    lifecyclePage($tenant, User::factory()->superAdmin()->create())
        ->set('status_reason', 'Hiatus.')
        ->set('requested_by', $requester($tenant))
        ->call('changeStatus', TenantStatus::Paused->value)
        ->assertHasErrors(['requested_by' => $message]);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'nobody' => [fn () => null, 'Choose the agency admin who asked for the pause.'],
    'its staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()->id, 'Choose one of the agency\'s active admins.'],
    'a deactivated admin' => [fn (Tenant $tenant) => User::factory()->role(Role::Admin)->for($tenant)->create(['is_active' => false])->id, 'Choose one of the agency\'s active admins.'],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(Tenant::factory())->create()->id, 'Choose one of the agency\'s active admins.'],
]);

test('pausing and closing must say why', function (TenantStatus $status, string $message) {
    $tenant = Tenant::factory()->create();

    expect(fn () => app(ChangeTenantStatusAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, $status, ['status_reason' => '']))
        ->toThrow(ValidationException::class, $message);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active);
})->with([
    'pause' => [TenantStatus::Paused, 'Say why the agency is being paused. The agency will see this reason.'],
    'close' => [TenantStatus::Closed, 'Say why the agency is being closed. The agency will see this reason.'],
]);

test('closing an agency cancels its live subscription and keeps every record', function () {
    freezeSecond();
    $tenant = Tenant::factory()->create();
    $live = Subscription::factory()->for($tenant)->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    app(ChangeTenantStatusAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, TenantStatus::Closed, ['status_reason' => 'Agency ceased trading.']);

    $live->refresh();
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Closed)
        ->and($live->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($live->active_for_tenant_id)->toBeNull()
        ->and($live->cancel_reason)->toBe('Agency ceased trading.')
        ->and($live->ends_on?->toDateString())->toBe(today()->toDateString())
        ->and(User::find($staff->id))->not->toBeNull();

    $central = PlatformAuditEvent::sole();
    expect($central->action)->toBe(AuditAction::TenantClosed)
        ->and($central->context)->toBe(['cancelled_subscription_id' => $live->id]);

    expect(tenantAuditLog($tenant)->sole()->action)->toBe(AuditAction::TenantClosed);
});

test('a closed agency is reopened only once it is on a plan again', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Closed]);

    lifecyclePage($tenant, $superAdmin)
        ->call('changeStatus', TenantStatus::Active->value)
        ->assertHasErrors(['status' => 'Put the agency on a plan before reopening it.']);
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Closed);

    lifecyclePage($tenant, $superAdmin)
        ->set('subscription_plan_id', SubscriptionPlan::factory()->create()->id)
        ->call('startSubscription')
        ->call('changeStatus', TenantStatus::Active->value)
        ->assertHasNoErrors();

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::TenantReactivated)->count())->toBe(1);
});
