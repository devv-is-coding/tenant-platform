<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Billing\AutoPayGateway;
use App\Services\Billing\PlatformCheckout;
use Illuminate\Validation\ValidationException;

class ManageAutoPayAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private AutoPayGateway $gateway,
    ) {}

    /**
     * Keep the card a completed platform setup session saved (TA-33), and
     * turn auto-pay on: the Admin saves a card to have invoices charged to
     * it. A card replacing another lets the old one go. The same session
     * delivered twice changes nothing.
     *
     * @param  array<string, mixed>  $session  the Checkout session object of the event
     * @return bool whether a card was saved now
     */
    public function saveCard(array $session): bool
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $setupIntentId = $session['setup_intent'] ?? null;
        $tenant = is_string($metadata['tenant_id'] ?? null) ? Tenant::query()->find($metadata['tenant_id']) : null;

        if (($metadata['purpose'] ?? null) !== PlatformCheckout::PURPOSE_CARD || ($session['status'] ?? null) !== 'complete' || ! is_string($setupIntentId) || $tenant === null) {
            return false;
        }

        $card = $this->gateway->cardOfSetupIntent($setupIntentId);
        $previous = $tenant->stripe_payment_method_id;

        if ($previous === $card['payment_method']) {
            return false;
        }

        $tenant->forceFill([
            'stripe_payment_method_id' => $card['payment_method'],
            'card_brand' => $card['brand'],
            'card_last4' => $card['last4'],
            'auto_pay_since' => $tenant->auto_pay_since ?? now(),
        ])->save();

        $admin = is_numeric($metadata['admin_id'] ?? null) ? User::query()->find((int) $metadata['admin_id']) : null;

        $this->auditRecorder->recordPlatformEvent(AuditAction::SubscriptionCardSaved, $admin, $tenant, __(':card saved; auto-pay is on.', [
            'card' => $this->describeCard($card['brand'], $card['last4']),
        ]));

        if ($previous !== null) {
            $this->gateway->detach($previous);
        }

        return true;
    }

    /**
     * Charge the agency's new invoices to its saved card from now on.
     *
     * @throws ValidationException for an agency with no saved card
     */
    public function turnOn(User $admin, Tenant $tenant): void
    {
        if ($tenant->stripe_payment_method_id === null) {
            throw ValidationException::withMessages(['auto_pay' => __('Save a card first. Auto-pay charges the saved card.')]);
        }

        if ($tenant->auto_pay_since !== null) {
            return;
        }

        $tenant->forceFill(['auto_pay_since' => now()])->save();

        $this->auditRecorder->recordPlatformEvent(AuditAction::SubscriptionAutoPayOn, $admin, $tenant, __('Auto-pay turned on.'));
    }

    /**
     * Stop charging the saved card. The card is kept, to turn it back on.
     */
    public function turnOff(User $admin, Tenant $tenant): void
    {
        if ($tenant->auto_pay_since === null) {
            return;
        }

        $tenant->forceFill(['auto_pay_since' => null])->save();

        $this->auditRecorder->recordPlatformEvent(AuditAction::SubscriptionAutoPayOff, $admin, $tenant, __('Auto-pay turned off.'));
    }

    /**
     * Forget the saved card, which turns auto-pay off, and let Stripe let it go.
     *
     * @throws ValidationException for an agency with no saved card
     */
    public function removeCard(User $admin, Tenant $tenant): void
    {
        $paymentMethodId = $tenant->stripe_payment_method_id ?? throw ValidationException::withMessages(['auto_pay' => __('There is no saved card.')]);
        $card = $this->describeCard($tenant->card_brand, $tenant->card_last4);

        $tenant->forceFill(['stripe_payment_method_id' => null, 'card_brand' => null, 'card_last4' => null, 'auto_pay_since' => null])->save();

        $this->auditRecorder->recordPlatformEvent(AuditAction::SubscriptionCardRemoved, $admin, $tenant, __(':card removed; auto-pay is off.', ['card' => $card]));

        $this->gateway->detach($paymentMethodId);
    }

    /**
     * Describe a card as the billing page shows it, e.g. "Visa ending 4242".
     */
    public function describeCard(?string $brand, ?string $last4): string
    {
        return __(':brand ending :last4', ['brand' => ucfirst($brand ?? __('card')), 'last4' => $last4 ?? '····']);
    }
}
