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
use Livewire\Livewire;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutExceptionHandling;

/*
 * Deleting an agency must keep its own database, so these tests provision real
 * tenant databases rather than faking tenant creation.
 */

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // A tenant with billing history cannot be force-deleted, so clear that first.
    SubscriptionPayment::withTrashed()->forceDelete();
    SubscriptionInvoice::withTrashed()->forceDelete();
    Subscription::withTrashed()->forceDelete();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

function agencyDatabaseKept(Tenant $tenant): bool
{
    return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
}

test('deleting an agency soft-deletes it with its users, domains and billing, and keeps its database', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete Travel']);
    $tenant->domains()->create(['domain' => 'dumaguete.localhost']);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $live = Subscription::factory()->for($tenant)->create();
    $invoice = SubscriptionInvoice::factory()->for($live)->create();
    SubscriptionPayment::factory()->for($invoice, 'invoice')->create();
    $superAdmin = User::factory()->superAdmin()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.tenants.index'));

    expect(Tenant::find('dumaguete'))->toBeNull()
        ->and(Tenant::withTrashed()->find('dumaguete')?->deleted_at)->not->toBeNull()
        ->and(User::find($admin->id))->toBeNull()
        ->and(Domain::query()->where('tenant_id', 'dumaguete')->count())->toBe(0)
        ->and(Subscription::query()->where('tenant_id', 'dumaguete')->count())->toBe(0)
        ->and(SubscriptionInvoice::count())->toBe(0)
        ->and(SubscriptionPayment::count())->toBe(0)
        ->and(Subscription::withTrashed()->find($live->id)?->active_for_tenant_id)->toBeNull()
        ->and(Subscription::withTrashed()->find($live->id)?->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(agencyDatabaseKept($tenant))->toBeTrue();

    $central = PlatformAuditEvent::query()->where('action', AuditAction::TenantDeleted)->sole();
    expect($central->tenant_id)->toBe('dumaguete')
        ->and($central->description)->toBe('Agency closed its business.')
        ->and($central->context)->toBe(['cascaded' => [
            'subscription' => 1,
            'subscription_invoice' => 1,
            'subscription_payment' => 1,
            'user' => 1,
            'domain' => 1,
        ]]);

    $notice = $tenant->run(fn () => AuditEvent::sole());
    expect($notice->action)->toBe(AuditAction::TenantDeleted)
        ->and($notice->description)->toBe('Agency closed its business.');
});

test('a deleted agency\'s address no longer identifies it', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete']);
    $tenant->domains()->create(['domain' => 'dumaguete.localhost']);
    get('http://dumaguete.localhost/login')->assertOk();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency');

    withoutExceptionHandling();
    expect(fn () => get('http://dumaguete.localhost/login'))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
});

test('deleting an agency needs a reason, and nothing is deleted without one', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', '')
        ->call('deleteAgency')
        ->assertHasErrors(['delete_reason' => 'Say why this is being deleted. The reason is kept in the audit log.']);

    expect($tenant->fresh()?->deleted_at)->toBeNull()
        ->and(PlatformAuditEvent::count())->toBe(0);
});

test('only a force delete drops an agency\'s database', function () {
    $tenant = Tenant::factory()->create();

    $tenant->delete();
    expect(agencyDatabaseKept($tenant))->toBeTrue();

    $tenant->forceDelete();
    expect(agencyDatabaseKept($tenant))->toBeFalse();
});

test('the audit log names a deleted agency without linking to its page', function () {
    $tenant = Tenant::factory()->create(['id' => 'dumaguete', 'name' => 'Dumaguete Travel']);
    $superAdmin = User::factory()->superAdmin()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency');

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.audit.index')
        ->assertSeeInOrder(['Tenant Deleted', 'Dumaguete Travel', 'Agency deleted'])
        ->assertDontSeeHtml('href="'.route('admin.tenants.show', 'dumaguete').'"');
});
