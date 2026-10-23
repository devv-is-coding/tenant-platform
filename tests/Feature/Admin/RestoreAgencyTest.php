<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Restoring an agency writes a notice into its own audit log, as deleting it
 * did, so these tests provision real tenant databases.
 */

function deletedAgencyPage(Tenant $tenant, ?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

function deleteAgencyForRestore(Tenant $tenant): Tenant
{
    deletedAgencyPage($tenant)
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency')
        ->assertHasNoErrors();

    return Tenant::withTrashed()->findOrFail($tenant->id);
}

test('restoring an agency brings back exactly what its delete took, and its address works again', function () {
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete Travel']);
    $domain = $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $deletedEarlier = tap(User::factory()->role(Role::Staff)->for($tenant)->create())->delete();
    $live = Subscription::factory()->for($tenant)->create();
    $invoice = SubscriptionInvoice::factory()->for($live)->create();
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $deleted = deleteAgencyForRestore($tenant);

    deletedAgencyPage($deleted, $superAdmin)
        ->call('restoreAgency')
        ->assertHasNoErrors()
        ->assertDontSee('Restore agency');

    expect(Tenant::find($tenant->id))->not->toBeNull()
        ->and(Domain::find($domain->id))->not->toBeNull()
        ->and(User::find($admin->id))->not->toBeNull()
        ->and(User::find($deletedEarlier->id))->toBeNull()
        ->and($invoice->fresh()?->trashed())->toBeFalse()
        ->and($payment->fresh()?->trashed())->toBeFalse()
        ->and($live->fresh()?->trashed())->toBeFalse()
        ->and($live->fresh()?->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($live->fresh()?->active_for_tenant_id)->toBeNull();

    // Signed out, so the login page renders rather than redirecting home.
    Auth::logout();
    get("http://{$tenant->id}.localhost/login")->assertOk();

    $central = PlatformAuditEvent::query()->where('action', AuditAction::TenantRestored)->sole();
    expect($central->tenant_id)->toBe($tenant->id)
        ->and($central->actor_id)->toBe($superAdmin->id)
        ->and($central->context)->toBe(['restored' => [
            'domain' => [$domain->id],
            'user' => [$admin->id],
            'subscription' => [$live->id],
            'subscription_invoice' => [$invoice->id],
            'subscription_payment' => [$payment->id],
        ]]);

    $notice = $tenant->run(fn () => AuditEvent::query()->where('action', AuditAction::TenantRestored)->sole());
    expect($notice->actor_id)->toBe($superAdmin->id);
});

test('a deleted agency\'s page opens with its deletion and a restore button, and nothing else can change it', function () {
    $tenant = deleteAgencyForRestore(Tenant::factory()->create(['name' => 'Dumaguete Travel']));
    $superAdmin = User::factory()->superAdmin()->create();

    actingAs($superAdmin)->get(route('admin.tenants.show', $tenant->id))->assertOk();

    deletedAgencyPage($tenant, $superAdmin)
        ->assertSee('Agency closed its business.')
        ->assertSee('Restore agency')
        ->assertDontSee('Delete agency')
        ->assertDontSee('Suspend')
        ->call('deleteAgency')
        ->assertForbidden();

    expect($superAdmin->can('changeStatus', $tenant))->toBeFalse()
        ->and($superAdmin->can('manageSubscription', $tenant))->toBeFalse();
});

test('restoring an agency is refused once the user is no longer a super admin', function () {
    $tenant = deleteAgencyForRestore(Tenant::factory()->create());
    $page = deletedAgencyPage($tenant);
    actingAs(User::factory()->role(Role::Customer)->create());

    $page->call('restoreAgency')->assertForbidden();

    expect(Tenant::find($tenant->id))->toBeNull()
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::TenantRestored)->count())->toBe(0);
});
