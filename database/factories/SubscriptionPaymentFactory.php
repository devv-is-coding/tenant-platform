<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows made here do not touch the invoice's `amount_paid_minor`; only
 * RecordSubscriptionPaymentAction keeps the two in step.
 *
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    /**
     * A successful bank transfer of 1,000.00, paid now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_invoice_id' => SubscriptionInvoice::factory(),
            'tenant_id' => fn (array $attributes): mixed => SubscriptionInvoice::query()->whereKey($attributes['subscription_invoice_id'])->value('tenant_id'),
            'reference' => fn (): string => 'PLATPAY-TEST-'.fake()->unique()->numerify('######'),
            'amount_minor' => 100000,
            'currency' => 'PHP',
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentRecordStatus::Succeeded,
            'paid_at' => now(),
        ];
    }

    /**
     * Indicate that the gateway declined the payment.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Stripe,
            'status' => PaymentRecordStatus::Failed,
            'paid_at' => null,
            'failure_reason' => 'Card declined.',
        ]);
    }
}
