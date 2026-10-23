<?php

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PlanFeature;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureTenantHasFeature;
use App\Models\AuditEvent;
use App\Models\PlatformAuditEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Suspending an agency writes into its own database, so these tests provision
 * real tenant databases rather than faking tenant creation.
 */

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // A tenant with billing history cannot be deleted, so clear that first.
    SubscriptionPayment::withTrashed()->forceDelete();
    SubscriptionInvoice::withTrashed()->forceDelete();
    Subscription::withTrashed()->forceDelete();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

function agencyPageFor(Tenant $tenant): Testable
{
    return Livewire::actingAs(User::factory()->superAdmin()->create())->test('pages::admin.tenants.show', ['tenant' => $tenant]);
}

test('what a super admin does to one agency never reaches another', function () {
    $acted = Tenant::factory()->create(['name' => 'Acted On']);
    $other = Tenant::factory()->create(['name' => 'Left Alone']);
    $actedInvoice = SubscriptionInvoice::factory()->for(Subscription::factory()->for($acted))->create();
    $otherSubscription = Subscription::factory()->for($other)->create();
    $otherInvoice = SubscriptionInvoice::factory()->for($otherSubscription)->create();

    agencyPageFor($acted)
        ->set('status_reason', 'Unpaid fees.')
        ->call('changeStatus', TenantStatus::Suspended->value)
        ->set('subscription_plan_id', SubscriptionPlan::factory()->create()->id)
        ->call('startSubscription')
        ->call('openPaymentForm', $actedInvoice->id)
        ->set(['payment_amount' => '1000', 'payment_method' => PaymentMethod::Cash->value, 'payment_paid_at' => today()->toDateString()])
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($other->fresh()?->status)->toBe(TenantStatus::Active)
        ->and($other->fresh()?->status_reason)->toBeNull()
        ->and($otherSubscription->fresh()?->plan_name)->toBe($otherSubscription->plan_name)
        ->and($otherSubscription->fresh()?->active_for_tenant_id)->toBe($other->id)
        ->and(Subscription::query()->where('tenant_id', $other->id)->count())->toBe(1)
        ->and($otherInvoice->fresh()?->amount_paid_minor)->toBe(0)
        ->and(SubscriptionPayment::query()->where('tenant_id', $other->id)->count())->toBe(0)
        ->and(PlatformAuditEvent::query()->where('tenant_id', $other->id)->count())->toBe(0)
        ->and($other->run(fn () => AuditEvent::count()))->toBe(0)
        ->and(PlatformAuditEvent::query()->where('tenant_id', $acted->id)->pluck('action')->all())->toContain(
            AuditAction::TenantSuspended,
            AuditAction::SubscriptionPlanChanged,
            AuditAction::SubscriptionPaymentRecorded,
        );
});

test('one agency\'s tier never reaches another\'s requests, in either order', function () {
    // No gated routes exist yet, so a probe on the tenant stack stands in.
    Route::middleware(['tenant', EnsureTenantHasFeature::using(PlanFeature::Analytics)])->get('/analytics-probe', fn () => 'analytics');
    $pro = Tenant::factory()->create(['id' => 'pro-agency']);
    $basic = Tenant::factory()->create(['id' => 'basic-agency']);
    Subscription::factory()->for($pro)->create(['tier' => PlanTier::Pro]);
    Subscription::factory()->for($basic)->create(['tier' => PlanTier::Basic]);
    $pro->domains()->create(['domain' => 'pro-agency.localhost']);
    $basic->domains()->create(['domain' => 'basic-agency.localhost']);

    foreach ([$pro, $basic, $pro, $basic] as $tenant) {
        // Ended first, as at the start of a real request: the test application
        // keeps the previous request's tenant initialized.
        tenancy()->end();

        $response = actingAs(User::factory()->role(Role::Staff)->for($tenant)->create())->get("http://{$tenant->id}.localhost/analytics-probe");

        $tenant->is($pro) ? $response->assertOk() : $response->assertForbidden();
    }
});

test('the invoice a payment form is recording against cannot be swapped from the browser', function () {
    $tenant = Tenant::factory()->create();
    $own = SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create();
    $other = SubscriptionInvoice::factory()->create();

    expect(fn () => agencyPageFor($tenant)->call('openPaymentForm', $own->id)->set('paying_invoice_id', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(SubscriptionPayment::count())->toBe(0);
});

test('a payment cannot be recorded without opening an invoice of this agency first', function () {
    $tenant = Tenant::factory()->create();
    SubscriptionInvoice::factory()->create();

    agencyPageFor($tenant)
        ->set(['payment_amount' => '1', 'payment_paid_at' => today()->toDateString()])
        ->call('recordPayment')
        ->assertNotFound();

    expect(SubscriptionPayment::count())->toBe(0);
});
