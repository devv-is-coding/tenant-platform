<?php

use App\Actions\Tenants\ChangeTenantStatusAction;
use App\Actions\Tenants\DeleteTenantAction;
use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionAgencyDatabase;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function provisioningPage(Tenant $tenant, ?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('an agency still being set up says so on its page, and cannot be activated there', function () {
    $tenant = agencyBeingSetUp();

    provisioningPage($tenant)
        ->assertSee(['Setting up', 'Setting up the agency\'s database'])
        ->assertDontSee(['Activate', 'Retry setup']);
});

test('an agency still being set up cannot be activated', function () {
    $tenant = agencyBeingSetUp();

    expect(fn () => app(ChangeTenantStatusAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, TenantStatus::Active, []))
        ->toThrow(ValidationException::class, 'The agency is setting up, so it cannot be made active.');

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Provisioning);
});

test('a failed setup shows its error, and retrying it queues the setup again from where it stopped', function () {
    Queue::fake([ProvisionAgencyDatabase::class]);
    $tenant = agencyBeingSetUp('Setup failed: Connection refused.');
    $superAdmin = User::factory()->superAdmin()->create();

    provisioningPage($tenant, $superAdmin)
        ->assertSee(['The agency\'s setup failed', 'Setup failed: Connection refused.'])
        ->call('retryProvisioning')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === "Setting up {$tenant->name} again.")
        ->assertSee('Setting up the agency\'s database');

    expect($tenant->fresh()?->status_reason)->toBeNull();
    Queue::assertPushed(ProvisionAgencyDatabase::class, fn (ProvisionAgencyDatabase $job): bool => $job->tenant->is($tenant) && $job->resuming);
    $event = PlatformAuditEvent::sole();
    expect($event->action)->toBe(AuditAction::TenantProvisioningRetried)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->description)->toBe('Setup failed: Connection refused.');
});

test('a setup still running cannot be retried beside itself', function () {
    Queue::fake([ProvisionAgencyDatabase::class]);
    $tenant = agencyBeingSetUp();

    provisioningPage($tenant)
        ->call('retryProvisioning')
        ->assertHasErrors(['provisioning' => "{$tenant->name} is not waiting on a failed setup."]);

    Queue::assertNothingPushed();
    expect(PlatformAuditEvent::count())->toBe(0);
});

test('an agency set up already has no setup to retry', function () {
    provisioningPage(tenantOnDomain(TenantStatus::Pending))
        ->call('retryProvisioning')
        ->assertForbidden();
});

test('an agency still being set up can be deleted, with no notice for the database it does not have yet', function () {
    $tenant = agencyBeingSetUp();

    app(DeleteTenantAction::class)->handle(User::factory()->superAdmin()->create(), $tenant, ['delete_reason' => 'Onboarded by mistake.']);

    expect($tenant->fresh()?->trashed())->toBeTrue()
        ->and(PlatformAuditEvent::query()->pluck('action')->all())->toBe([AuditAction::TenantDeleted]);
});
