<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * The agency paying the platform by card, through Stripe Checkout on the
 * platform's own account: the same one that collects agencies' customer
 * payments (decided 2026-11-22), with the `purpose` in the metadata keeping
 * the two apart. Stripe only collects. The platform's billing engine stays the
 * record, and the webhook writes what was paid (RecordPlatformCheckoutAction).
 *
 * Two things are sold. **A move up** (or onto a plan, with none) starts at
 * once, at the new plan's price less the unused, already paid days of the
 * current one (decided 2026-11-22). **What an invoice still owes.** A move
 * down, or across at the same tier, is not sold: it waits for the paid period
 * to end (SchedulePlanChangeAction).
 *
 * Never called inside a database transaction: row locks would be held for as
 * long as Stripe takes. Each session is keyed, so asking twice for the same
 * thing is one session.
 */
class PlatformCheckout
{
    /**
     * The metadata purpose of a plan bought by the agency.
     */
    public const PURPOSE_PLAN = 'platform_plan';

    /**
     * The metadata purpose of a platform invoice paid by card.
     */
    public const PURPOSE_INVOICE = 'platform_invoice';

    /**
     * Determine if moving the agency from its live plan to this one is a move
     * up, paid for and started at once.
     */
    public static function isUpgrade(?Subscription $live, SubscriptionPlan $plan): bool
    {
        return $live === null || ! $live->tier->includes($plan->tier);
    }

    /**
     * Price a move up onto the plan today: its price less the credit for the
     * live plan's unused, paid days.
     *
     * @return array{amount: Money, credit: Money}
     *
     * @throws ValidationException for a plan that is not a move up, or one that leaves nothing to pay
     */
    public function quote(Tenant $tenant, SubscriptionPlan $plan, CarbonInterface $today): array
    {
        $live = $tenant->liveSubscription()->first();

        if (! self::isUpgrade($live, $plan)) {
            throw ValidationException::withMessages(['plan' => __(':plan is not a move up, so it starts when the paid period ends.', ['plan' => $plan->name])]);
        }

        $creditMinor = $live !== null && $live->currency === $plan->currency
            ? min($live->unusedCreditMinor($today), $plan->amount_minor)
            : 0;

        if ($plan->amount_minor - $creditMinor <= 0) {
            throw ValidationException::withMessages(['plan' => __('There is nothing left to pay for :plan. Ask the platform to make the move.', ['plan' => $plan->name])]);
        }

        return [
            'amount' => Money::ofMinor($plan->amount_minor - $creditMinor, $plan->currency),
            'credit' => Money::ofMinor($creditMinor, $plan->currency),
        ];
    }

    /**
     * Get a Stripe Checkout page for moving the agency up onto the plan.
     *
     * @throws ValidationException for a withdrawn plan, or one that is not a move up
     * @throws LogicException inside a database transaction
     * @throws ApiErrorException when Stripe refuses
     */
    public function planUrl(Tenant $tenant, SubscriptionPlan $plan, User $admin): string
    {
        $this->ensureNoOpenTransaction();

        if ($plan->trashed()) {
            throw ValidationException::withMessages(['plan' => __('That plan is no longer offered. Choose another.')]);
        }

        $today = today();
        ['amount' => $amount, 'credit' => $credit] = $this->quote($tenant, $plan, $today);

        return $this->session($tenant, $admin, $amount, $plan->name, [
            'purpose' => self::PURPOSE_PLAN,
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => (string) $plan->id,
            'amount_minor' => (string) $amount->minor,
            'credit_minor' => (string) $credit->minor,
            'admin_id' => (string) $admin->id,
        ], "plan:{$tenant->id}:{$plan->id}:{$today->toDateString()}:{$amount->minor}");
    }

    /**
     * Get a Stripe Checkout page for what the invoice still owes.
     *
     * @throws ValidationException for an invoice with nothing left to pay
     * @throws LogicException inside a database transaction
     * @throws ApiErrorException when Stripe refuses
     */
    public function invoiceUrl(SubscriptionInvoice $invoice, User $admin): string
    {
        $this->ensureNoOpenTransaction();

        $tenant = $invoice->tenant()->firstOrFail();
        $owed = $invoice->status->isOutstanding() ? $invoice->outstandingMinor() : 0;

        if ($owed <= 0) {
            throw ValidationException::withMessages(['invoice' => __(':reference has nothing left to pay.', ['reference' => $invoice->reference])]);
        }

        return $this->session($tenant, $admin, Money::ofMinor($owed, $invoice->currency), __('Invoice :reference', ['reference' => $invoice->reference]), [
            'purpose' => self::PURPOSE_INVOICE,
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => (string) $invoice->id,
            'admin_id' => (string) $admin->id,
        ], "invoice:{$invoice->id}:{$invoice->amount_paid_minor}");
    }

    /**
     * Open a one-off Checkout payment for the amount, for the agency as the
     * platform's customer, and get its page.
     *
     * @param  array<string, string>  $metadata
     */
    private function session(Tenant $tenant, User $admin, Money $amount, string $name, array $metadata, string $key): string
    {
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $this->customerFor($tenant, $admin),
            'client_reference_id' => $tenant->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($amount->currency),
                    'unit_amount' => $amount->minor,
                    'product_data' => ['name' => $name],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => route('tenant.billing', ['checkout' => 'paid']),
            'cancel_url' => route('tenant.billing', ['checkout' => 'cancelled']),
        ], ['idempotency_key' => "checkout:{$key}"]);

        return $session->url ?? throw new LogicException("Stripe returned no page for checkout session {$session->id}.");
    }

    /**
     * The agency's Stripe customer on the platform's account, made on its
     * first card payment.
     */
    private function customerFor(Tenant $tenant, User $admin): string
    {
        if ($tenant->stripe_customer_id !== null) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->stripe()->customers->create([
            'name' => $tenant->name ?? $tenant->id,
            'email' => $admin->email,
            'metadata' => ['purpose' => 'platform', 'tenant_id' => $tenant->id],
        ], ['idempotency_key' => "platform-customer:{$tenant->id}"]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    private function stripe(): StripeClient
    {
        return Cashier::stripe();
    }

    /**
     * @throws LogicException
     */
    private function ensureNoOpenTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Stripe is never called inside a database transaction: its row locks would be held for as long as Stripe takes.');
        }
    }
}
