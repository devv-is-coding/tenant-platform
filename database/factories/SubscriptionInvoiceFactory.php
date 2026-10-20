<?php

namespace Database\Factories;

use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    /**
     * An open invoice for a monthly period starting today, due in ten days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStartOn = now()->startOfDay();

        return [
            'subscription_id' => Subscription::factory(),
            'tenant_id' => fn (array $attributes): mixed => Subscription::query()->whereKey($attributes['subscription_id'])->value('tenant_id'),
            'reference' => fn (): string => 'PLAT-TEST-'.fake()->unique()->numerify('######'),
            'period_start_on' => $periodStartOn,
            'period_end_on' => $periodStartOn->copy()->addMonth()->subDay(),
            'issued_on' => $periodStartOn,
            'due_on' => $periodStartOn->copy()->addDays(10),
            'amount_minor' => 250000,
            'currency' => 'PHP',
            'amount_paid_minor' => 0,
            'status' => SubscriptionInvoiceStatus::Open,
        ];
    }

    /**
     * Indicate that the invoice fell due some days ago and is still unpaid.
     */
    public function overdue(int $daysOverdue = 5): static
    {
        return $this->state(fn () => [
            'issued_on' => now()->subDays($daysOverdue + 10)->startOfDay(),
            'due_on' => now()->subDays($daysOverdue)->startOfDay(),
        ]);
    }

    /**
     * Indicate that part of the invoice has been paid.
     */
    public function partiallyPaid(int $amountPaidMinor = 100000): static
    {
        return $this->state(fn () => [
            'amount_paid_minor' => $amountPaidMinor,
            'status' => SubscriptionInvoiceStatus::PartiallyPaid,
        ]);
    }

    /**
     * Indicate that the invoice has been paid in full.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_paid_minor' => $attributes['amount_minor'],
            'status' => SubscriptionInvoiceStatus::Paid,
        ]);
    }

    /**
     * Indicate that the platform withdrew the charge.
     */
    public function void(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionInvoiceStatus::Void,
            'voided_on' => now(),
            'void_reason' => 'Issued in error.',
        ]);
    }
}
