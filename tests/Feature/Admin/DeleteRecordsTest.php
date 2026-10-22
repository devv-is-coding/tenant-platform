<?php

use App\Actions\Subscriptions\BillSubscriptionAction;
use App\Actions\Subscriptions\RecordSubscriptionPaymentAction;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Billing rows live in the central database, so no tenant database is provisioned.
    Event::fake([TenantCreated::class]);
});

/**
 * Open the agency page, confirm deleting one of its records and give a reason.
 */
function deleteFromAgencyPage(Tenant $tenant, string $type, int $id, string $reason = 'Recorded by mistake.', ?User $superAdmin = null): Testable
{
    return Livewire::actingAs($superAdmin ?? User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->call('confirmDelete', $type, $id)
        ->set('delete_reason', $reason)
        ->call('deleteRecord');
}

test('deleting a payment keeps the row and recalculates what the invoice has been paid', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $invoice = SubscriptionInvoice::factory()->create(['amount_minor' => 250000]);
    $kept = app(RecordSubscriptionPaymentAction::class)->handle($superAdmin, $invoice, ['amount' => '1000', 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()]);
    $mistake = app(RecordSubscriptionPaymentAction::class)->handle($superAdmin, $invoice, ['amount' => '1500', 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()]);
    expect($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::Paid);

    deleteFromAgencyPage($invoice->tenant, 'payment', $mistake->id, superAdmin: $superAdmin)->assertHasNoErrors();

    expect(SubscriptionPayment::withTrashed()->find($mistake->id)?->deleted_at)->not->toBeNull()
        ->and(SubscriptionPayment::query()->pluck('id')->all())->toBe([$kept->id])
        ->and($invoice->refresh()->amount_paid_minor)->toBe(100000)
        ->and($invoice->status)->toBe(SubscriptionInvoiceStatus::PartiallyPaid);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionPaymentDeleted)->sole();
    expect($event->description)->toBe('Recorded by mistake.')
        ->and($event->subject_type)->toBe('subscription_payment')
        ->and($event->subject_id)->toBe($mistake->id)
        ->and($event->changes)->toBe([
            'amount_paid_minor' => ['old' => 250000, 'new' => 100000],
            'status' => ['old' => SubscriptionInvoiceStatus::Paid->value, 'new' => SubscriptionInvoiceStatus::PartiallyPaid->value],
        ]);
});

test('a payment recorded after one was deleted takes the next reference, not the deleted one', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $invoice = SubscriptionInvoice::factory()->create(['reference' => 'PLAT-2026-11-0007']);
    $first = app(RecordSubscriptionPaymentAction::class)->handle($superAdmin, $invoice, ['amount' => '100', 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()]);
    deleteFromAgencyPage($invoice->tenant, 'payment', $first->id, superAdmin: $superAdmin);

    $next = app(RecordSubscriptionPaymentAction::class)->handle($superAdmin, $invoice, ['amount' => '100', 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()]);

    expect($next->reference)->toBe('PLATPAY-2026-11-0007-2');
});

test('deleting a payment on a void invoice leaves it void', function () {
    $invoice = SubscriptionInvoice::factory()->void()->create();
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create();

    deleteFromAgencyPage($invoice->tenant, 'payment', $payment->id)->assertHasNoErrors();

    expect($invoice->refresh()->status)->toBe(SubscriptionInvoiceStatus::Void);
});

test('deleting an invoice deletes its payments with it and records which', function () {
    $invoice = SubscriptionInvoice::factory()->create();
    $payments = SubscriptionPayment::factory()->for($invoice, 'invoice')->count(2)->create();

    deleteFromAgencyPage($invoice->tenant, 'invoice', $invoice->id)->assertHasNoErrors();

    expect(SubscriptionInvoice::count())->toBe(0)
        ->and(SubscriptionInvoice::withTrashed()->count())->toBe(1)
        ->and(SubscriptionPayment::count())->toBe(0)
        ->and(SubscriptionPayment::onlyTrashed()->count())->toBe(2);

    $event = PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionInvoiceDeleted)->sole();
    expect($event->context)->toBe(['cascaded' => ['subscription_payment' => $payments->modelKeys()]]);
});

test('deleting the last overdue invoice moves a past-due subscription back to active', function () {
    travelTo('2026-10-21 09:00:00');
    $subscription = Subscription::factory()->pastDue()->create();
    $invoice = SubscriptionInvoice::factory()->for($subscription)->overdue()->create();

    deleteFromAgencyPage($subscription->tenant, 'invoice', $invoice->id)->assertHasNoErrors();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

test('the billing run neither re-issues a deleted invoice\'s period nor reuses its number', function () {
    travelTo('2026-10-10 09:00:00');
    $subscription = Subscription::factory()->create(['starts_on' => '2026-10-10', 'current_period_start_on' => '2026-10-10', 'current_period_end_on' => '2026-11-09', 'next_billing_on' => '2026-11-10']);
    $deleted = app(BillSubscriptionAction::class)->handle($subscription, today())['invoices'][0];
    deleteFromAgencyPage($subscription->tenant, 'invoice', $deleted->id);

    $rerun = app(BillSubscriptionAction::class)->handle($subscription->refresh(), today());
    travelTo('2026-11-10 09:00:00');
    $nextPeriod = app(BillSubscriptionAction::class)->handle($subscription->refresh(), today())['invoices'];

    expect($rerun['invoices'])->toBe([])
        ->and($deleted->reference)->toBe('PLAT-2026-11-0001')
        ->and(array_map(fn (SubscriptionInvoice $invoice): string => $invoice->reference, $nextPeriod))->toBe(['PLAT-2026-12-0001']);
});

test('deleting a subscription deletes its invoices and their payments', function () {
    $subscription = Subscription::factory()->expired()->create();
    $invoice = SubscriptionInvoice::factory()->for($subscription)->create();
    $payment = SubscriptionPayment::factory()->for($invoice, 'invoice')->create();

    deleteFromAgencyPage($subscription->tenant, 'subscription', $subscription->id)->assertHasNoErrors();

    expect(Subscription::count())->toBe(0)
        ->and(SubscriptionInvoice::count())->toBe(0)
        ->and(SubscriptionPayment::count())->toBe(0)
        ->and(PlatformAuditEvent::query()->where('action', AuditAction::SubscriptionDeleted)->sole()->context)
        ->toBe(['cascaded' => ['subscription_payment' => [$payment->id], 'subscription_invoice' => [$invoice->id]]]);
});

test('deleting a live subscription frees the agency to be put on a new plan', function () {
    $live = Subscription::factory()->create();
    $tenant = $live->tenant;

    deleteFromAgencyPage($tenant, 'subscription', $live->id)->assertHasNoErrors();

    $trashed = Subscription::withTrashed()->find($live->id);
    expect($trashed?->active_for_tenant_id)->toBeNull()
        ->and($trashed?->status)->toBe(SubscriptionStatus::Cancelled);

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->set('subscription_plan_id', SubscriptionPlan::factory()->create(['name' => 'Agency Plus'])->id)
        ->call('startSubscription')
        ->assertHasNoErrors();

    expect(Subscription::query()->where('active_for_tenant_id', $tenant->id)->sole()->plan_name)->toBe('Agency Plus');
});

test('deleting an agency user soft-deletes the account and records it', function () {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();

    deleteFromAgencyPage($tenant, 'user', $staff->id)->assertHasNoErrors();

    expect(User::find($staff->id))->toBeNull()
        ->and(User::withTrashed()->find($staff->id)?->deleted_at)->not->toBeNull();

    $event = PlatformAuditEvent::query()->where('action', AuditAction::UserDeleted)->sole();
    expect($event->tenant_id)->toBe($tenant->id)
        ->and($event->subject_type)->toBe('user')
        ->and($event->subject_id)->toBe($staff->id);
});

test('every delete needs a reason, and nothing is deleted without one', function (string $type, Closure $makeRecord) {
    $record = $makeRecord();
    $tenant = Tenant::findOrFail($record->tenant_id);

    deleteFromAgencyPage($tenant, $type, $record->id, reason: '   ')
        ->assertHasErrors(['delete_reason' => 'Say why this is being deleted. The reason is kept in the audit log.']);

    expect($record->fresh()?->deleted_at)->toBeNull()
        ->and(PlatformAuditEvent::count())->toBe(0);
})->with([
    'a user' => ['user', fn () => User::factory()->role(Role::Staff)->for(Tenant::factory())->create()],
    'a subscription' => ['subscription', fn () => Subscription::factory()->create()],
    'an invoice' => ['invoice', fn () => SubscriptionInvoice::factory()->create()],
    'a payment' => ['payment', fn () => SubscriptionPayment::factory()->create()],
]);

test('another agency\'s record cannot be deleted from this agency\'s page', function (string $type, Closure $makeRecord) {
    $tenant = Tenant::factory()->create();
    $otherAgencysRecord = $makeRecord();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->call('confirmDelete', $type, $otherAgencysRecord->id)
        ->assertNotFound();

    expect($otherAgencysRecord->fresh()?->deleted_at)->toBeNull();
})->with([
    'a user' => ['user', fn () => User::factory()->role(Role::Staff)->for(Tenant::factory())->create()],
    'a subscription' => ['subscription', fn () => Subscription::factory()->create()],
    'an invoice' => ['invoice', fn () => SubscriptionInvoice::factory()->create()],
    'a payment' => ['payment', fn () => SubscriptionPayment::factory()->create()],
]);

test('an unknown record type is not found', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $tenant])
        ->call('confirmDelete', 'tenant', 1)
        ->assertNotFound();
});

test('the record a delete confirmation is open for cannot be swapped from the browser', function () {
    $own = SubscriptionInvoice::factory()->create();
    $other = SubscriptionInvoice::factory()->create();

    expect(fn () => Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test('pages::admin.tenants.show', ['tenant' => $own->tenant])
        ->call('confirmDelete', 'invoice', $own->id)
        ->set('deleting_id', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($other->fresh()?->deleted_at)->toBeNull();
});
