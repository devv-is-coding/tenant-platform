<?php

namespace Database\Factories;

use App\Enums\InventoryBatchReason;
use App\Models\InventoryBatch;
use App\Models\Option;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Batches live in an agency's own database: create them inside tenancy.
 *
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    /**
     * Ten units of opening stock on a new fixed-capacity option, added by no one
     * in particular.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'option_id' => Option::factory(),
            'quantity' => 10,
            'reason' => InventoryBatchReason::InitialStock,
        ];
    }

    /**
     * Indicate that the batch takes units away to correct a mistake.
     */
    public function correction(int $quantity = -1): static
    {
        return $this->state(fn () => [
            'quantity' => $quantity,
            'reason' => InventoryBatchReason::Correction,
        ]);
    }
}
