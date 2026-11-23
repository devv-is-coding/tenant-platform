<?php

namespace App\Actions\Subscriptions;

use App\Actions\Notifications\SendPlatformNoticeAction;
use App\Enums\AuditAction;
use App\Enums\PlatformNoticeType;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Audit\AuditRecorder;
use App\Services\Billing\AutoPayGateway;
use App\ValueObjects\Money;
use Carbon\CarbonInterface;

class ChargeInvoiceAutomaticallyAction
{
    /**
     * Days between the first failed charge and the one retry.
     */
    public const RETRY_AFTER_DAYS = 3;

    /**
     * Charges per invoice: the first, and one retry.
     */
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private AutoPayGateway $gateway,
        private RecordStripeSubscriptionPaymentAction $recordPayment,
        private SendPlatformNoticeAction $sendNotice,
        private AuditRecorder $auditRecorder,
    ) {}

    /**
     * Charge an invoice to its agency's saved card, if auto-pay is on and an
     * attempt is due (decided 2026-11-23): the first on the day it is issued,
     * one retry three days after a failure, then it waits to be paid by hand,
     * which is always possible. Only invoices issued since the day auto-pay was
     * turned on are charged (one issued that same morning counts), so turning
     * it on never charges older ones.
     *
     * The attempt is taken under the invoice's lock before Stripe is called,
     * and each attempt is keyed, so a run that dies mid-charge never charges
     * twice. A charge Stripe confirms is recorded at once; one still
     * processing is recorded by its webhook. A decline, or a card that wants
     * the cardholder to confirm (not possible off-session), is a failure, and
     * the Admin is told.
     *
     * @return 'paid'|'pending'|'failed'|'skipped'
     */
    public function handle(SubscriptionInvoice $invoice, CarbonInterface $today): string
    {
        $tenant = $invoice->tenant()->first();

        if ($tenant === null || ! $tenant->hasAutoPay()) {
            return 'skipped';
        }

        $attempt = $this->takeAttempt($invoice, $tenant, $today);

        if ($attempt === null) {
            return 'skipped';
        }

        $invoice->refresh();
        $amount = Money::ofMinor($invoice->outstandingMinor(), $invoice->currency);
        $result = $this->gateway->charge($tenant, $invoice, $amount, $attempt);

        if ($result['status'] === 'succeeded' && $result['id'] !== null) {
            $this->recordPayment->handle($invoice, $amount, 'stripe:payment_intent:'.$result['id'], $result['id']);

            return 'paid';
        }

        if ($result['status'] === 'processing') {
            return 'pending';
        }

        $this->failed($tenant, $invoice, $amount, $attempt, $result['reason'] ?? ($result['status'] === 'requires_action'
            ? __('the card asks the cardholder to confirm the payment')
            : __('the card was declined')));

        return 'failed';
    }

    /**
     * Take the invoice's next attempt, if one is due, recording it and when a
     * retry would fall.
     */
    private function takeAttempt(SubscriptionInvoice $invoice, Tenant $tenant, CarbonInterface $today): ?int
    {
        return $invoice->getConnection()->transaction(function () use ($invoice, $tenant, $today): ?int {
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOutstanding() || $locked->outstandingMinor() <= 0 || $tenant->auto_pay_since === null) {
                return null;
            }

            $due = match ($locked->auto_charge_attempts) {
                0 => $locked->issued_on->gte($tenant->auto_pay_since->copy()->startOfDay()),
                1 => $locked->auto_charge_retry_on !== null && $locked->auto_charge_retry_on->lte($today),
                default => false,
            };

            if (! $due) {
                return null;
            }

            $attempt = $locked->auto_charge_attempts + 1;

            $locked->update([
                'auto_charge_attempts' => $attempt,
                'auto_charge_retry_on' => $attempt < self::MAX_ATTEMPTS ? $today->copy()->addDays(self::RETRY_AFTER_DAYS) : null,
            ]);

            return $attempt;
        });
    }

    /**
     * Record the failed charge and tell the agency's Admin what happens next.
     */
    private function failed(Tenant $tenant, SubscriptionInvoice $invoice, Money $amount, int $attempt, string $reason): void
    {
        $invoice->refresh();
        $reason = rtrim(trim($reason), '.');

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::SubscriptionAutoPayFailed,
            null,
            $tenant,
            $reason,
            subject: $invoice,
            context: ['attempt' => $attempt],
        );

        $this->sendNotice->handle($tenant, PlatformNoticeType::AutoPayFailed, $invoice->auto_charge_retry_on !== null
            ? __('The automatic payment of :amount for :reference failed: :reason. It is tried once more on :date, or you can pay it now.', [
                'amount' => $amount->format(),
                'reference' => $invoice->reference,
                'reason' => $reason,
                'date' => $invoice->auto_charge_retry_on->toFormattedDateString(),
            ])
            : __('The automatic payment of :amount for :reference failed again: :reason. Pay it by hand before :date.', [
                'amount' => $amount->format(),
                'reference' => $invoice->reference,
                'reason' => $reason,
                'date' => $invoice->due_on->toFormattedDateString(),
            ]), $invoice);
    }
}
