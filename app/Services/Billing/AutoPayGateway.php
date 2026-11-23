<?php

namespace App\Services\Billing;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentMethod;
use Stripe\StripeClient;

/**
 * Stripe's side of auto-pay (TA-33), on the platform's own account: reading
 * the card an agency's Admin saved, charging it for an invoice while nobody
 * is at the screen, and letting it go.
 *
 * Never called inside a database transaction. A charge is keyed per invoice
 * and attempt, so the same attempt sent twice is one charge, and a decline,
 * or a card that wants the cardholder to confirm (3-D Secure, which cannot
 * happen off-session), comes back as an answer, not an exception.
 */
class AutoPayGateway
{
    /**
     * The metadata purpose of an automatic charge, so its webhook is told
     * apart from agencies' customer payments on the same account.
     */
    public const PURPOSE = 'platform_autopay';

    /**
     * Get the card a completed setup saved: Stripe's id for it, and the brand
     * and last four digits the billing page shows.
     *
     * @return array{payment_method: string, brand: string|null, last4: string|null}
     *
     * @throws LogicException inside a database transaction, or for a setup that saved no card
     * @throws ApiErrorException when Stripe refuses
     */
    public function cardOfSetupIntent(string $setupIntentId): array
    {
        $this->ensureNoOpenTransaction();

        $setupIntent = $this->stripe()->setupIntents->retrieve($setupIntentId, ['expand' => ['payment_method']]);
        $paymentMethod = $setupIntent->payment_method;

        if (! $paymentMethod instanceof PaymentMethod) {
            throw new LogicException("Setup {$setupIntentId} saved no card.");
        }

        return [
            'payment_method' => $paymentMethod->id,
            'brand' => $paymentMethod->card?->brand,
            'last4' => $paymentMethod->card?->last4,
        ];
    }

    /**
     * Charge the agency's saved card for the amount, for the invoice. Stripe's
     * answer as it is: the PaymentIntent's status, `failed` for a decline,
     * with Stripe's own reason for one.
     *
     * @return array{id: string|null, status: string, reason: string|null}
     *
     * @throws LogicException inside a database transaction
     * @throws ApiErrorException when Stripe refuses for a reason other than the card
     */
    public function charge(Tenant $tenant, SubscriptionInvoice $invoice, Money $amount, int $attempt): array
    {
        $this->ensureNoOpenTransaction();

        try {
            $intent = $this->stripe()->paymentIntents->create([
                'amount' => $amount->minor,
                'currency' => strtolower($amount->currency),
                'customer' => $tenant->stripe_customer_id,
                'payment_method' => $tenant->stripe_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => __('Invoice :reference', ['reference' => $invoice->reference]),
                'metadata' => [
                    'purpose' => self::PURPOSE,
                    'tenant_id' => $tenant->id,
                    'subscription_invoice_id' => (string) $invoice->id,
                    'attempt' => (string) $attempt,
                ],
            ], ['idempotency_key' => "autopay:{$invoice->id}:{$attempt}"]);
        } catch (CardException $declined) {
            return ['id' => null, 'status' => 'failed', 'reason' => $declined->getMessage()];
        }

        return ['id' => $intent->id, 'status' => (string) $intent->status, 'reason' => null];
    }

    /**
     * Let a saved card go, once the agency no longer uses it. Best effort:
     * a card Stripe has already let go of is no loss, so a refusal is only
     * logged.
     */
    public function detach(string $paymentMethodId): void
    {
        $this->ensureNoOpenTransaction();

        try {
            $this->stripe()->paymentMethods->detach($paymentMethodId);
        } catch (ApiErrorException $refused) {
            Log::warning('Stripe would not detach a saved card: '.$refused->getMessage(), ['payment_method' => $paymentMethodId]);
        }
    }

    private function stripe(): StripeClient
    {
        return Cashier::stripe();
    }

    /**
     * Refuse to call Stripe while any transaction is open, on any connection:
     * auto-pay runs centrally, so the default connection alone would not do.
     * Asked of the transactions manager, as after-commit events are, so a
     * test's own wrapping transaction is not taken for one.
     *
     * @throws LogicException
     */
    private function ensureNoOpenTransaction(): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Stripe is never called inside a database transaction: its row locks would be held for as long as Stripe takes.');
        }
    }
}
