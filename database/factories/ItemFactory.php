<?php

namespace Database\Factories;

use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Items live in an agency's own database: create them inside tenancy.
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * A draft tour, without its profile: add one with the profile's factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_type' => ItemType::Tour,
            'name' => fake()->unique()->words(3, true),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'item_status' => ItemStatus::Draft,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the item is a visa.
     */
    public function visa(): static
    {
        return $this->state(fn () => ['item_type' => ItemType::Visa]);
    }

    /**
     * Indicate that the item is a hotel.
     */
    public function hotel(): static
    {
        return $this->state(fn () => ['item_type' => ItemType::Hotel]);
    }

    /**
     * Indicate that staff sent the item for approval and it is waiting.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'item_status' => ItemStatus::Pending,
            'submitted_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that a manager approved the item, not yet published.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'item_status' => ItemStatus::Approved,
            'submitted_at' => now()->subDays(2),
            'approved_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that a manager sent the item back with a reason.
     */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'item_status' => ItemStatus::Rejected,
            'submitted_at' => now()->subDays(2),
            'rejected_at' => now()->subDay(),
            'rejection_reason' => 'Add the pickup time.',
        ]);
    }

    /**
     * Indicate that a manager approved the item and it is published.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'item_status' => ItemStatus::Published,
            'submitted_at' => now()->subDays(3),
            'approved_at' => now()->subDays(2),
            'published_at' => now()->subDay(),
        ]);
    }
}
