<?php

namespace App\Services\Inventory;

use App\Enums\AvailabilityMode;
use App\Models\Option;
use LogicException;

/**
 * How many units of an option can still be sold, derived from the ledger:
 * capacity is the sum of the option's live batches, and every allocation row
 * holds one unit. Nothing is stored, so nothing can drift.
 *
 * Covers fixed-capacity and Unlimited options. A per-night option's
 * availability depends on the nights asked for, so it is not answered here.
 */
class AvailabilityService
{
    /**
     * Get the option's capacity: the sum of its live batches, corrections included.
     */
    public function capacityOf(Option $option): int
    {
        return (int) $option->inventoryBatches()->sum('quantity');
    }

    /**
     * Get how many more units of the option can be sold, never below zero; null
     * for an Unlimited option, which never runs out.
     *
     * @throws LogicException for a per-night option
     */
    public function availableFor(Option $option): ?int
    {
        return match ($option->availability_mode) {
            AvailabilityMode::Unlimited => null,
            AvailabilityMode::Nightly => throw new LogicException("Option [{$option->id}] is sold by the night; its availability depends on the nights asked for."),
            AvailabilityMode::Capacity => max(0, $this->capacityOf($option) - $this->allocatedUnitsOf($option)),
        };
    }

    /**
     * Count the units of the option held by bookings: one allocation row each.
     */
    private function allocatedUnitsOf(Option $option): int
    {
        return $option->getConnection()->table('inventory_allocations')->where('option_id', $option->id)->count();
    }
}
