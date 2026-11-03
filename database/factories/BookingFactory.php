<?php

namespace Database\Factories;

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
}
