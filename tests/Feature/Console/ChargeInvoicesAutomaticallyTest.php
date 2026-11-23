<?php

use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

/**
 * The agency's ₱600.00 invoice issued on 1 December 2026.
 */
function invoiceIssuedFirstOfDecember(Tenant $tenant): SubscriptionInvoice
{
    return SubscriptionInvoice::factory()->for(Subscription::factory()->for($tenant))->create(['issued_on' => '2026-12-01', 'amount_minor' => 60000]);
}

test('the daily run charges what auto-pay is due to pay, and leaves the rest', function () {
    Event::fake([TenantCreated::class]);
    travelTo('2026-12-01 06:00:00');
    [$onAutoPay, $paysByHand] = Tenant::factory()->count(2)->create()->all();
    $onAutoPay->forceFill(['stripe_customer_id' => 'cus_1', 'stripe_payment_method_id' => 'pm_1', 'auto_pay_since' => '2026-11-01 00:00:00'])->save();
    $charged = invoiceIssuedFirstOfDecember($onAutoPay);
    $untouched = invoiceIssuedFirstOfDecember($paysByHand);
    fakeStripe(['POST /v1/payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    artisan('subscriptions:auto-charge')
        ->expectsOutputToContain('Charged 1 invoice(s); 0 failed, 0 still processing.')
        ->assertSuccessful();

    expect([$charged->fresh()?->status, $untouched->fresh()?->status])->toBe([SubscriptionInvoiceStatus::Paid, SubscriptionInvoiceStatus::Open]);
});
