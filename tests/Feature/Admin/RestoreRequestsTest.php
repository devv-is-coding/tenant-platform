<?php

use App\Actions\Restore\RequestRestoreAction;
use App\Enums\AuditAction;
use App\Enums\RestoreRequestStatus;
use App\Enums\Role;
use App\Models\PlatformAuditEvent;
use App\Models\RestoreRequest;
use App\Models\SubscriptionInvoice;
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
    // Restore requests and the records they point at are central, so no tenant
    // database is provisioned.
    Event::fake([TenantCreated::class]);
});

function restoreRequestsPage(?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())->test('pages::admin.restore-requests.index');
}

/**
 * A staff account of the agency, already deleted.
 */
function deletedStaffOf(Tenant $tenant): User
{
    return tap(User::factory()->role(Role::Staff)->for($tenant)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']))->delete();
}

test('an agency admin asks for one of the agency\'s deleted records back, and it is recorded', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $staff = deletedStaffOf($tenant);

    $request = app(RequestRestoreAction::class)->handle($admin, $tenant, $staff, ['reason' => ' Deleted the wrong account. ']);

    expect($request->status)->toBe(RestoreRequestStatus::Pending)
        ->and($request->restorable_type)->toBe('user')
        ->and($request->restorable_id)->toBe($staff->id)
        ->and($request->requested_by)->toBe($admin->id)
        ->and($request->reason)->toBe('Deleted the wrong account.');

    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::RestoreRequested)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('restore_request')
        ->and($event->description)->toBe('Deleted the wrong account.');
});

test('only the agency\'s own admin may ask, and only for its own records', function (Closure $actor) {
    $tenant = Tenant::factory()->create();
    $staff = deletedStaffOf($tenant);

    expect(fn () => app(RequestRestoreAction::class)->handle($actor($tenant), $tenant, $staff, ['reason' => 'Please.']))
        ->toThrow(AuthorizationException::class);

    expect(RestoreRequest::count())->toBe(0);
})->with([
    'the agency\'s manager' => [fn (Tenant $tenant) => User::factory()->role(Role::Manager)->for($tenant)->create()],
    'the agency\'s staff' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s admin' => [fn () => User::factory()->role(Role::Admin)->for(Tenant::factory())->create()],
    'a super admin' => [fn () => User::factory()->superAdmin()->create()],
]);

test('a request the admin cannot make is refused and nothing is filed', function (Closure $record, array $input, string $message) {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();

    $restorable = $record($tenant, $admin);
    $filedBefore = RestoreRequest::count();

    expect(fn () => app(RequestRestoreAction::class)->handle($admin, $tenant, $restorable, $input))
        ->toThrow(ValidationException::class, $message);

    expect(RestoreRequest::count())->toBe($filedBefore)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::RestoreRequested)->count())->toBe(0);
})->with([
    'no reason' => [fn (Tenant $tenant) => deletedStaffOf($tenant), ['reason' => ''], 'Say why this should be restored.'],
    'a record that is not deleted' => [fn (Tenant $tenant) => User::factory()->role(Role::Staff)->for($tenant)->create(), ['reason' => 'Please.'], 'Only a deleted record of your own agency can be restored.'],
    'another agency\'s record' => [fn () => deletedStaffOf(Tenant::factory()->create()), ['reason' => 'Please.'], 'Only a deleted record of your own agency can be restored.'],
    'one already requested' => [function (Tenant $tenant, User $admin) {
        $staff = deletedStaffOf($tenant);
        RestoreRequest::factory()->create(['tenant_id' => $tenant->id, 'restorable_id' => $staff->id, 'requested_by' => $admin->id]);

        return $staff;
    }, ['reason' => 'Please.'], 'A restore of this record has already been requested.'],
]);

test('a super admin restores what a request asks for, closing the request', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $request = RestoreRequest::factory()->create(['reason' => 'Deleted the wrong account.']);

    restoreRequestsPage($superAdmin)
        ->call('restore', $request->id)
        ->assertHasNoErrors();

    $request->refresh();
    expect($request->restorable?->trashed())->toBeFalse()
        ->and($request->status)->toBe(RestoreRequestStatus::Restored)
        ->and($request->decided_by)->toBe($superAdmin->id)
        ->and($request->decided_at)->not->toBeNull();

    $event = PlatformAuditEvent::query()->where('action', AuditAction::UserRestored)->sole();
    expect($event->description)->toBe('Deleted the wrong account.')
        ->and($event->context)->toBe(['restore_request_id' => $request->id]);
});

test('declining a request needs a reason', function () {
    $request = RestoreRequest::factory()->create();

    restoreRequestsPage()
        ->call('confirmDecline', $request->id)
        ->call('decline')
        ->assertHasErrors(['decision_reason' => 'Say why the request is declined.']);

    expect($request->fresh()?->status)->toBe(RestoreRequestStatus::Pending)
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('declining a request keeps the record deleted and is recorded', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $request = RestoreRequest::factory()->create();

    restoreRequestsPage($superAdmin)
        ->call('confirmDecline', $request->id)
        ->set('decision_reason', 'The account was deleted on purpose.')
        ->call('decline')
        ->assertHasNoErrors();

    $request->refresh();
    expect($request->status)->toBe(RestoreRequestStatus::Declined)
        ->and($request->decision_reason)->toBe('The account was deleted on purpose.')
        ->and($request->decided_by)->toBe($superAdmin->id)
        ->and($request->restorable?->trashed())->toBeTrue();

    $event = PlatformAuditEvent::query()->where('action', AuditAction::RestoreDeclined)->sole();
    expect($event->subject_type)->toBe('restore_request')
        ->and($event->subject_id)->toBe($request->id)
        ->and($event->description)->toBe('The account was deleted on purpose.');
});

test('a decided request cannot be decided again', function (string $action, array $arguments) {
    $request = RestoreRequest::factory()->declined()->create();

    $page = restoreRequestsPage();
    if ($action === 'decline') {
        $page->call('confirmDecline', $request->id)->set('decision_reason', 'Again.');
    }

    $page->call($action, ...($arguments === [] ? [] : [$request->id]))
        ->assertHasErrors();

    expect($request->fresh()?->status)->toBe(RestoreRequestStatus::Declined)
        ->and($request->restorable?->trashed())->toBeTrue();
})->with([
    'restored' => ['restore', ['id']],
    'declined again' => ['decline', []],
]);

test('the page lists pending requests oldest first, and the dashboard points to them', function () {
    $older = RestoreRequest::factory()->create(['tenant_id' => Tenant::factory()->create(['name' => 'Dumaguete Travel'])->id]);
    RestoreRequest::factory()->create(['tenant_id' => Tenant::factory()->create(['name' => 'Tanjay Tours'])->id]);
    RestoreRequest::factory()->declined()->create(['tenant_id' => Tenant::factory()->create(['name' => 'Zamboanguita Dive'])->id]);
    SubscriptionInvoice::factory()->create();

    restoreRequestsPage()
        ->assertSeeInOrder(['Waiting for a decision', 'Dumaguete Travel', 'Tanjay Tours', 'Recently decided', 'Zamboanguita Dive']);

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.dashboard')
        ->assertSee('2 requests waiting')
        ->assertSeeHtml('href="'.route('admin.restore-requests.index').'"');

    expect($older->fresh()?->status)->toBe(RestoreRequestStatus::Pending);
});

test('restoring or declining is refused once the user is no longer a super admin', function (string $action) {
    $request = RestoreRequest::factory()->create();
    $page = restoreRequestsPage()->set('decision_reason', 'No.');
    actingAs(User::factory()->role(Role::Admin)->for($request->tenant)->create());

    $page->call($action, $request->id)->assertForbidden();

    expect($request->fresh()?->status)->toBe(RestoreRequestStatus::Pending);
})->with(['restore', 'confirmDecline']);
