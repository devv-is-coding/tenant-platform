<?php

namespace Database\Factories;

use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bookings live in an agency's own database: create them inside tenancy. A
 * factory booking has no lines and holds nothing; book through BookingService
 * for one that does.
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * A pending, unpaid 499.00 booking for a new customer of the current agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'BK-'.fake()->unique()->bothify('????-######'),
            'customer_id' => fn (): int => User::factory()->role(Role::Customer)->create(['tenant_id' => tenant()?->getTenantKey()])->id,
            'customer_name' => fn (array $attributes): string => User::query()->whereKey($attributes['customer_id'])->firstOrFail()->full_name,
            'customer_email' => fn (array $attributes): string => User::query()->whereKey($attributes['customer_id'])->firstOrFail()->email,
            'currency' => fn (): string => is_string(tenant('currency')) ? tenant('currency') : 'PHP',
            'subtotal_minor' => 49900,
            'total_minor' => 49900,
        ];
    }

    /**
     * A booking the manager has approved.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'approval_status' => BookingApprovalStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    /**
     * A booking paid in full. Records no payment: pay through PaymentService
     * for a ledger behind it.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount_paid_minor' => $attributes['total_minor'],
            'payment_status' => BookingPaymentStatus::Paid,
        ]);
    }

    /**
     * A booking whose guest was served.
     */
    public function completed(): static
    {
        return $this->state(['fulfilment_status' => FulfilmentStatus::Completed]);
    }

    /**
     * A booking that was cancelled after approval.
     */
    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'approval_status' => BookingApprovalStatus::Cancelled,
            'approved_at' => now(),
            'cancelled_at' => now(),
        ]);
    }
}
