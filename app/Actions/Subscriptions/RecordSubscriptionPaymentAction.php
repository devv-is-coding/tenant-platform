<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordSubscriptionPaymentAction
{
    public function __construct(private AuditRecorder $auditRecorder, private ClearPastDueAction $clearPastDue) {}

    /**
     * Record money an agency paid the platform by hand, such as a bank
     * transfer, against one invoice.
     *
     * The invoice's `amount_paid_minor` is then recomputed as a SUM over its
     * payments, never incremented, and its status derived from the result, so
     * neither can drift from the ledger. Both rows are locked, subscription
     * first as billing does, so two payments recorded at once take turns and
     * cannot together pay more than is owed.
     *
     * Settling the last overdue invoice moves a PastDue subscription back to
     * Active. The agency's own status is left alone: reactivating a suspended
     * agency stays a super admin's decision, never a side effect of a payment.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, SubscriptionInvoice $invoice, array $input): SubscriptionPayment
    {
        $validated = Validator::make($input, $this->rules($invoice), $this->messages())->validate();

        $method = PaymentMethod::from((int) $validated['method']);
        $paidAt = CarbonImmutable::parse($validated['paid_at'])->startOfDay();

        return $invoice->getConnection()->transaction(function () use ($actor, $invoice, $validated, $method, $paidAt): SubscriptionPayment {
            $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $amount = Money::parse($validated['amount'], $locked->currency);
            $this->ensureInvoiceCanTake($locked, $amount, $this->sumPaidMinor($locked));

            $payment = $locked->payments()->create([
                'tenant_id' => $locked->tenant_id,
                // Counted with deleted payments too: they keep their references.
                'reference' => 'PLATPAY-'.Str::after($locked->reference, 'PLAT-').'-'.($locked->payments()->withTrashed()->count() + 1),
                'amount_minor' => $amount->minor,
                'currency' => $amount->currency,
                'method' => $method,
                'status' => PaymentRecordStatus::Succeeded,
                'paid_at' => $paidAt,
                'recorded_by' => $actor->id,
            ]);

            $previousAmountPaidMinor = $locked->amount_paid_minor;
            $previousStatus = $locked->status;
            $locked->recomputeAmountPaid();

            $this->auditRecorder->recordPlatformEvent(
                AuditAction::SubscriptionPaymentRecorded,
                $actor,
                $subscription->tenant,
                changes: [
                    'amount_paid_minor' => ['old' => $previousAmountPaidMinor, 'new' => $locked->amount_paid_minor],
                    'status' => ['old' => $previousStatus->value, 'new' => $locked->status->value],
                ],
                subject: $payment,
            );

            $this->clearPastDue->handle($actor, $subscription);

            return $payment;
        });
    }

    /**
     * Get the validation rules. Amounts are typed in major units, with at
     * most two decimal places and no thousands separators. Only the methods
     * a person records by hand are offered: card and Stripe payments are only
     * ever confirmed by the gateway.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(SubscriptionInvoice $invoice): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0*(\.0*)?$/'],
            'method' => ['required', Rule::enum(PaymentMethod::class)->only(self::manualMethods())],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$invoice->issued_on->toDateString()],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => __('Enter the amount as a number with at most two decimal places, without commas.'),
            'amount.not_regex' => __('The amount must be more than zero.'),
            'paid_at.before_or_equal' => __('The payment date cannot be in the future.'),
            'paid_at.after_or_equal' => __('The payment date cannot be before the invoice was issued.'),
        ];
    }

    /**
     * Get the payment methods a super admin may record by hand.
     *
     * @return list<PaymentMethod>
     */
    public static function manualMethods(): array
    {
        return array_values(array_filter(
            PaymentMethod::cases(),
            fn (PaymentMethod $method): bool => $method->isRecordedByStaff(),
        ));
    }

    /**
     * Sum what the invoice's ledger says has been paid, in minor units. The
     * ledger, not the stored `amount_paid_minor`, is the source of truth.
     */
    private function sumPaidMinor(SubscriptionInvoice $invoice): int
    {
        return (int) $invoice->payments()->countingAsPaid()->sum('amount_minor');
    }

    /**
     * Refuse a payment the invoice cannot take as it stands now: one against
     * an invoice already settled or voided, or one larger than what the
     * ledger says is still owed.
     *
     * @throws ValidationException
     */
    private function ensureInvoiceCanTake(SubscriptionInvoice $invoice, Money $amount, int $paidMinor): void
    {
        $outstandingMinor = max(0, $invoice->amount_minor - $paidMinor);

        if (! $invoice->status->acceptsPayment()) {
            throw ValidationException::withMessages([
                'amount' => __('Invoice :reference is :status and takes no more payments.', [
                    'reference' => $invoice->reference,
                    'status' => mb_strtolower($invoice->status->label()),
                ]),
            ]);
        }

        if ($amount->minor > $outstandingMinor) {
            throw ValidationException::withMessages([
                'amount' => __('The amount is more than the :balance still owed.', [
                    'balance' => Money::ofMinor($outstandingMinor, $invoice->currency)->format(),
                ]),
            ]);
        }
    }
}
