<?php

namespace App\Services\Payment;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * The app's one way to Stripe, through Cashier's client and keys.
 *
 * **The platform collects** (decided 2026-11-04): every agency's online
 * payments go to the platform's own Stripe account. What ties a Stripe object
 * back to its agency is its metadata (`tenant_id`, plus the booking's
 * reference or the refund's id), which the webhook reads to find its way into
 * the right agency's database. Stripe Connect per agency stays possible
 * later: a `stripe_account_id` in the agency's `tenants.data` would be passed
 * as the `stripe_account` option here, with no schema change.
 *
 * **Never inside a database transaction.** Every method talks to Stripe over
 * HTTP, and a transaction held across a network call holds its row locks for
 * as long as Stripe takes; each refuses to run inside one. Each Stripe write
 * also carries an idempotency key, so a retried call cannot charge or refund
 * twice.
 *
 * Payment state is never set from here: money counts only once Stripe's
 * signed webhook says it arrived (StripeWebhookController).
 */
class StripeGateway
{
    use RecordsAuditEvents;

    /**
     * How long a customer has to pay a balance link, in days.
     */
    public const DAYS_UNTIL_DUE = 7;

    /**
     * Get a Stripe-hosted invoice page for what the booking still owes, the
     * balance only, never the total: a booking paid 400.00 cash of 599.00
     * gets a link for 199.00.
     *
     * An open invoice for the same balance is reused, so asking twice gives
     * the same page; one for an amount since changed (cash recorded meanwhile)
     * is voided and replaced, so the customer can never pay the old figure.
     *
     * @throws InvalidArgumentException when nothing is owed, or the booking no longer takes payments
     * @throws LogicException inside a database transaction
     * @throws ApiErrorException when Stripe refuses
     */
    public function balanceInvoiceUrl(Booking $booking): string
    {
        $this->ensureNoOpenTransaction();

        $balance = $booking->balance();

        if (! $booking->approval_status->isActive()) {
            throw new InvalidArgumentException("{$booking->reference} is ".mb_strtolower($booking->approval_status->label()).' and takes no payments.');
        }

        if ($balance->minor === 0) {
            throw new InvalidArgumentException("{$booking->reference} has nothing left to pay.");
        }

        $stripe = $this->stripe();

        if ($booking->stripe_invoice_id !== null) {
            $existing = $stripe->invoices->retrieve($booking->stripe_invoice_id);

            if ($existing->status === 'open' && $existing->amount_remaining === $balance->minor && $existing->hosted_invoice_url !== null) {
                return $existing->hosted_invoice_url;
            }

            if ($existing->status === 'open') {
                $stripe->invoices->voidInvoice($existing->id);
            }
        }

        $metadata = ['tenant_id' => $this->tenantKey(), 'booking_reference' => $booking->reference];
        // One key per booking and balance: the same balance asked for twice is one invoice.
        $key = "{$this->tenantKey()}:{$booking->reference}:{$booking->amount_paid_minor}";

        $customerId = $booking->stripe_customer_id ?? $stripe->customers->create([
            'email' => $booking->customer_email,
            'name' => $booking->customer_name,
            'metadata' => $metadata,
        ], ['idempotency_key' => "customer:{$this->tenantKey()}:{$booking->reference}"])->id;

        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'currency' => strtolower($balance->currency),
            'collection_method' => 'send_invoice',
            'days_until_due' => self::DAYS_UNTIL_DUE,
            'auto_advance' => false,
            'pending_invoice_items_behavior' => 'exclude',
            'metadata' => $metadata,
        ], ['idempotency_key' => "invoice:{$key}"]);

        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $invoice->id,
            'amount' => $balance->minor,
            'currency' => strtolower($balance->currency),
            'description' => __('Balance for booking :reference', ['reference' => $booking->reference]),
            'metadata' => $metadata,
        ], ['idempotency_key' => "invoice-item:{$key}"]);

        $finalized = $stripe->invoices->finalizeInvoice($invoice->id, [], ['idempotency_key' => "finalize:{$key}"]);
        $url = $finalized->hosted_invoice_url ?? throw new LogicException("Stripe returned no page for invoice {$finalized->id}.");

        $booking->forceFill([
            'stripe_customer_id' => $customerId,
            'stripe_invoice_id' => $finalized->id,
            'stripe_invoice_url' => $url,
        ])->save();

        $this->recordAuditEvent(AuditAction::PaymentLinkGenerated, $booking, __('Payment link for the :balance balance of :reference.', [
            'balance' => $balance->format(),
            'reference' => $booking->reference,
        ]), context: ['stripe_invoice_id' => $finalized->id, 'amount_minor' => $balance->minor]);

        return $url;
    }

    /**
     * Find the PaymentIntent that paid an invoice, which a later refund of
     * that money goes back through.
     *
     * @throws LogicException inside a database transaction
     * @throws ApiErrorException
     */
    public function paymentIntentOfInvoice(string $invoiceId): ?string
    {
        $this->ensureNoOpenTransaction();

        foreach ($this->stripe()->invoicePayments->all(['invoice' => $invoiceId, 'status' => 'paid'])->data as $invoicePayment) {
            $intent = $invoicePayment->payment->payment_intent ?? null;

            if ($intent !== null) {
                return is_string($intent) ? $intent : $intent->id;
            }
        }

        return null;
    }

    /**
     * Send an approved refund back through the card payment it reverses.
     *
     * Keyed by the agency and the refund, so processing the same refund again
     * gets Stripe's first answer back rather than a second refund.
     *
     * @return array{id: string, status: string}
     *
     * @throws LogicException inside a database transaction, or for a payment Stripe did not settle
     * @throws ApiErrorException when Stripe refuses
     */
    public function refund(Refund $refund, Payment $payment): array
    {
        $this->ensureNoOpenTransaction();

        $reverses = match (true) {
            $payment->stripe_payment_intent_id !== null => ['payment_intent' => $payment->stripe_payment_intent_id],
            $payment->stripe_charge_id !== null => ['charge' => $payment->stripe_charge_id],
            default => throw new LogicException("Payment {$payment->reference} has no Stripe payment to refund."),
        };

        $stripeRefund = $this->stripe()->refunds->create([
            ...$reverses,
            'amount' => $refund->amount_minor,
            'metadata' => [
                'tenant_id' => $this->tenantKey(),
                'refund_id' => (string) $refund->id,
                'payment_reference' => $payment->reference,
            ],
        ], ['idempotency_key' => "refund:{$this->tenantKey()}:{$refund->id}"]);

        return ['id' => $stripeRefund->id, 'status' => (string) $stripeRefund->status];
    }

    /**
     * The Stripe client, on the platform's keys (config/cashier.php).
     */
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

    /**
     * @throws LogicException
     */
    private function tenantKey(): string
    {
        $key = tenant()?->getTenantKey();

        if (! is_string($key)) {
            throw new LogicException('Stripe is only used inside an agency, which its metadata names.');
        }

        return $key;
    }
}
