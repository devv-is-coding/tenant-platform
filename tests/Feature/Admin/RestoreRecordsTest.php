<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

beforeEach(function () {
    // Billing rows and agency users live in the central database, so no tenant
    // database is provisioned.
    Event::fake([TenantCreated::class]);
});

function agencyPageForRestore(Tenant $tenant, ?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

/**
 * Delete a record the way the agency page does, so its audit row records what
 * the delete took with it.
 */
function deleteThroughAgencyPage(Tenant $tenant, string $type, int $id): void
{
    agencyPageForRestore($tenant)
        ->call('confirmDelete', $type, $id)
        ->set('delete_reason', 'Deleted by mistake.')
        ->call('deleteRecord')
        ->assertHasNoErrors();
}

function restoreThroughAgencyPage(Tenant $tenant, string $type, int $id, ?User $superAdmin = null): Testable
{
    return agencyPageForRestore($tenant, $superAdmin)
        ->call('confirmRestore', $type, $id)
        ->call('restoreRecord');
}

test('restoring a payment brings it back and recalculates what its invoice has been paid', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $invoice = SubscriptionInvoice::factory()->create(['amount_minor' => 250000]);
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['amount_minor' => 250000]);
    $invoice->recomputeAmountPaid();
    deleteThroughAgencyPage($invoice->tenant, 'payment', $payment->id);
    expect($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::Open);

    restoreThroughAgencyPage($invoice->tenant, 'payment', $payment->id, $superAdmin)->assertHasNoErrors();

    expect($payment->fresh()?->trashed())->toBeFalse()
        ->and($invoice->refresh()->amount_paid_minor)->toBe(250000)
        ->and($invoice->status)->toBe(SubscriptionInvoiceStatus::Paid);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionPaymentRestored)->sole();
    expect($event->tenant_id)->toBe($invoice->tenant_id)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_type)->toBe('subscription_payment')
        ->and($event->subject_id)->toBe($payment->id);
});

test('restoring an invoice brings back the payments deleted with it, not one deleted earlier on purpose', function () {
    $invoice = SubscriptionInvoice::factory()->create(['amount_minor' => 300000]);
    $earlier = SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['amount_minor' => 100000]);
    $withIt = SubscriptionPayment::factory()->for($invoice, 'invoice')->create(['amount_minor' => 200000]);
    deleteThroughAgencyPage($invoice->tenant, 'payment', $earlier->id);
    deleteThroughAgencyPage($invoice->tenant, 'invoice', $invoice->id);

    restoreThroughAgencyPage($invoice->tenant, 'invoice', $invoice->id)->assertHasNoErrors();

    expect($invoice->fresh()?->trashed())->toBeFalse()
        ->and($withIt->fresh()?->trashed())->toBeFalse()
        ->and($earlier->fresh()?->trashed())->toBeTrue()
        ->and($invoice->fresh()?->amount_paid_minor)->toBe(200000)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionInvoiceRestored)->sole()->context)
        ->toBe(['restored' => ['subscription_payment' => [$withIt->id]]]);
});

test('restoring a subscription brings back its invoices and payments, and it stays cancelled out of the active slot', function () {
    $live = Subscription::factory()->create();
    $invoice = SubscriptionInvoice::factory()->for($live)->create();
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create();
    deleteThroughAgencyPage($live->tenant, 'subscription', $live->id);

    restoreThroughAgencyPage($live->tenant, 'subscription', $live->id)->assertHasNoErrors();

    $restored = $live->fresh();
    expect($restored?->trashed())->toBeFalse()
        ->and($restored?->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($restored?->active_for_tenant_id)->toBeNull()
        ->and($invoice->fresh()?->trashed())->toBeFalse()
        ->and($payment->fresh()?->trashed())->toBeFalse();
});

test('restoring an agency user brings their account back', function () {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    deleteThroughAgencyPage($tenant, 'user', $staff->id);

    restoreThroughAgencyPage($tenant, 'user', $staff->id)->assertHasNoErrors();

    expect(User::find($staff->id))->not->toBeNull();
    $event = PlatformAuditEvent::query()->where('action', AuditAction::UserRestored)->sole();
    expect($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($staff->id);
});

test('a record whose parent is still deleted is refused until the parent is restored', function (Closure $arrange, string $type, string $message) {
    [$tenant, $record] = $arrange();

    restoreThroughAgencyPage($tenant, $type, $record->id)->assertHasErrors(['restore' => $message]);

    expect($record->fresh()?->trashed())->toBeTrue()
        ->and(PlatformAuditEvent::query()->where('action', 'like', '%restored')->count())->toBe(0);
})->with([
    'an invoice of a deleted subscription' => [function () {
        $invoice = SubscriptionInvoice::factory()->create(['reference' => 'PLAT-2026-11-0001']);
        deleteThroughAgencyPage($invoice->tenant, 'subscription', $invoice->subscription_id);

        return [$invoice->tenant, $invoice];
    }, 'invoice', 'Invoice PLAT-2026-11-0001 belongs to a deleted subscription. Restore the subscription first.'],
    'a payment of a deleted invoice' => [function () {
        $payment = SubscriptionPayment::factory()->create(['reference' => 'PLATPAY-1']);
        deleteThroughAgencyPage($payment->tenant, 'invoice', $payment->subscription_invoice_id);

        return [$payment->tenant, $payment];
    }, 'payment', 'Payment PLATPAY-1 belongs to a deleted invoice. Restore the invoice first.'],
]);

test('the agency page lists only this agency\'s deleted records, newest first', function () {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
    $invoice = SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create(['reference' => 'PLAT-2026-11-0001']);
    $other = User::factory()->role(Role::Staff)->for(Tenant::factory())->create(['first_name' => 'Other', 'middle_name' => null, 'last_name' => 'Agency']);
    $staff->delete();
    travel(1)->minute();
    $invoice->delete();
    $other->delete();

    agencyPageForRestore($tenant)
        ->assertSeeInOrder(['Deleted records', 'PLAT-2026-11-0001', 'Ana Reyes'])
        ->assertDontSee('Other Agency');
});

test('another agency\'s deleted record cannot be restored from this agency\'s page', function () {
    $tenant = Tenant::factory()->create();
    $other = User::factory()->role(Role::Staff)->for(Tenant::factory())->create();
    $other->delete();

    agencyPageForRestore($tenant)->call('confirmRestore', 'user', $other->id)->assertNotFound();

    expect($other->fresh()?->trashed())->toBeTrue();
});

test('restoring is refused once the user is no longer a super admin', function (string $action) {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $staff->delete();
    $page = agencyPageForRestore($tenant)->call('confirmRestore', 'user', $staff->id);
    actingAs(User::factory()->role(Role::Admin)->for($tenant)->create());

    $page->call($action, ...($action === 'confirmRestore' ? ['user', $staff->id] : []))->assertForbidden();

    expect($staff->fresh()?->trashed())->toBeTrue();
})->with(['confirmRestore', 'restoreRecord']);

test('the directory\'s deleted filter lists only deleted agencies', function () {
    Tenant::factory()->create(['name' => 'Still Trading']);
    tap(Tenant::factory()->create(['name' => 'Closed Down']))->delete();

    $directory = Livewire::actingAs(User::factory()->superAdmin()->create())->test('pages::admin.tenants.index');

    $directory->assertSee('Still Trading')->assertDontSee('Closed Down');
    $directory->set('status', 'deleted')->assertSee('Closed Down')->assertDontSee('Still Trading');
});
