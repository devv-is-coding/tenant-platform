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

function agencyDatabaseKept(Tenant $tenant): bool
{
    return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
}

test('deleting an agency soft-deletes it with its staff, customers, domains and billing, and keeps its database', function () {
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete Travel']);
    $domain = $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $live = Subscription::factory()->for($tenant)->create();
    $invoice = SubscriptionInvoice::factory()->for($live)->create();
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create();
    $superAdmin = User::factory()->superAdmin()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.tenants.index'));

    expect(Tenant::find($tenant->id))->toBeNull()
        ->and(Tenant::withTrashed()->find($tenant->id)?->deleted_at)->not->toBeNull()
        ->and(User::find($admin->id))->toBeNull()
        ->and(User::find($customer->id))->toBeNull()
        ->and(Domain::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(SubscriptionInvoice::count())->toBe(0)
        ->and(SubscriptionPayment::count())->toBe(0)
        ->and(Subscription::withTrashed()->find($live->id)?->active_for_tenant_id)->toBeNull()
        ->and(Subscription::withTrashed()->find($live->id)?->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(agencyDatabaseKept($tenant))->toBeTrue();

    $central = PlatformAuditEvent::query()->where('action', AuditAction::TenantDeleted)->sole();
    expect($central->tenant_id)->toBe($tenant->id)
        ->and($central->description)->toBe('Agency closed its business.')
        ->and($central->context)->toBe(['cascaded' => [
            'subscription' => [$live->id],
            'subscription_invoice' => [$invoice->id],
            'subscription_payment' => [$payment->id],
            'user' => [$admin->id, $customer->id],
            'domain' => [$domain->id],
        ]]);

    $notice = $tenant->run(fn () => AuditEvent::sole());
    expect($notice->action)->toBe(AuditAction::TenantDeleted)
        ->and($notice->description)->toBe('Agency closed its business.');
});

test('a deleted agency\'s address no longer identifies it', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => "{$tenant->id}.localhost"]);
    get("http://{$tenant->id}.localhost/login")->assertOk();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency');

    withoutExceptionHandling();
    expect(fn () => get("http://{$tenant->id}.localhost/login"))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
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
    $tenant = Tenant::factory()->create(['name' => 'Dumaguete Travel']);
    $superAdmin = User::factory()->superAdmin()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('delete_reason', 'Agency closed its business.')
        ->call('deleteAgency');

    Livewire::actingAs($superAdmin)
        ->test('pages::admin.audit.index')
        ->assertSeeInOrder(['Tenant Deleted', 'Dumaguete Travel', 'Agency deleted'])
        ->assertDontSeeHtml('href="'.route('admin.tenants.show', $tenant->id).'"');
});
