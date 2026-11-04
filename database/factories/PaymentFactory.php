<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Payments live in an agency's own database: create them inside tenancy. A
 * factory payment does not recompute its booking's amounts; record one
 * through PaymentService for that.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * A succeeded 100.00 cash payment against a new booking, in its currency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'reference' => 'PAY-'.fake()->unique()->bothify('????-######'),
            'amount_minor' => 10000,
            'currency' => fn (array $attributes): string => Booking::query()->whereKey($attributes['booking_id'])->value('currency') ?? 'PHP',
            'method' => PaymentMethod::Cash,
            'status' => PaymentRecordStatus::Succeeded,
            'paid_at' => now(),
        ];
    }

    /**
     * A payment of the given amount, in that amount's currency.
     */
    public function amount(Money $amount): static
    {
        return $this->state(['amount_minor' => $amount->minor, 'currency' => $amount->currency]);
    }

    /**
     * A card payment confirmed by Stripe.
     */
    public function viaStripe(): static
    {
        return $this->state(fn (): array => [
            'method' => PaymentMethod::Stripe,
            'idempotency_key' => 'stripe:invoice:in_'.fake()->unique()->bothify('????????????'),
            'stripe_payment_intent_id' => 'pi_'.fake()->bothify('????????????'),
            'stripe_charge_id' => 'ch_'.fake()->bothify('????????????'),
        ]);
    }
}
