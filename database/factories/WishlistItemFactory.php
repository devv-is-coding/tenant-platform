<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Item;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Wishlist rows live in an agency's own database: create them inside tenancy.
 *
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    /**
     * A published item saved by a new customer of the current agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => fn (): int => User::factory()->role(Role::Customer)->create(['tenant_id' => tenant()?->getTenantKey()])->id,
            'item_id' => Item::factory()->published(),
        ];
    }
}
