<?php

namespace App\Services\Inventory;

use App\Enums\AvailabilityMode;
use App\Exceptions\OptionUnavailableException;
use App\Models\InventoryBatch;
use App\Models\Option;
use App\ValueObjects\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * How many units of an option can still be sold, derived from the ledger:
 * capacity comes from the option's live batches, and every allocation row
 * holds one unit for one date. Nothing is stored, so nothing can drift. The
 * sums themselves are AvailabilityCalculator's.
 *
 * - **Per night** (a hotel room): the fewest left on any night of the stay.
 * - **Fixed capacity** (a tour seat): what is left on the departure date, the
 *   date a booking holds its units on. A tour departs on its own date; another
 *   item's package is asked for a date. Without either, every live batch and
 *   every held unit counts, whatever its dates.
 * - **Unlimited** (a visa): never runs out, so there is no number.
 */
class AvailabilityService
{
    /**
     * Get the option's capacity: the sum of its live batches, corrections
     * included, whatever their effective dates.
     */
    public function capacityOf(Option $option): int
    {
        return (int) $option->inventoryBatches()->sum('quantity');
    }

    /**
     * Get how many more units of the option can be sold, never below zero; null
     * for an Unlimited option. A per-night option needs the stay; a
     * fixed-capacity one is counted on the stay's first date if given.
     *
     * @throws LogicException for a per-night option without a stay
     * @throws InvalidArgumentException for a stay with no nights
     */
    public function availableFor(Option $option, ?DateRange $stay = null): ?int
    {
        return match ($option->availability_mode) {
            AvailabilityMode::Unlimited => null,
            AvailabilityMode::Nightly => $this->availableForStay($option, $this->stayFor($option, $stay)),
            AvailabilityMode::Capacity => $this->availableOnDeparture($option, $stay),
        };
    }

    /**
     * Get the option's availability night by night, for a calendar.
     *
     * @return list<array{night: CarbonImmutable, capacity: int, held: int, available: int}>
     *
     * @throws LogicException for an Unlimited option, which has no stock to show
     * @throws InvalidArgumentException for a range with no nights
     */
    public function nightlyBreakdown(Option $option, DateRange $range): array
    {
        if ($option->availability_mode === AvailabilityMode::Unlimited) {
            throw new LogicException("Option [{$option->id}] is Unlimited; it has no stock to show night by night.");
        }

        return $this->calculatorFor($option, $range)->breakdown($range);
    }

    /**
     * Refuse a booking of the option that the ledger cannot cover, or a stay
     * outside the option's shortest and longest.
     *
     * @throws InvalidArgumentException for a quantity below one, or a stay of the wrong length
     * @throws OptionUnavailableException when fewer units are left than asked for
     */
    public function assertCanAllocate(Option $option, int $quantity, ?DateRange $stay = null): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("A booking takes at least one unit, not {$quantity}.");
        }

        if ($option->availability_mode === AvailabilityMode::Nightly) {
            $this->ensureStayLength($option, $this->stayFor($option, $stay));
        }

        $available = $this->availableFor($option, $stay);

        if ($available !== null && $available < $quantity) {
            throw new OptionUnavailableException($option, $quantity, $available);
        }
    }

    private function availableForStay(Option $option, DateRange $stay): int
    {
        return $this->calculatorFor($option, $stay)->availableFor($stay);
    }

    /**
     * What is left on the departure date: the stay's first date, else the
     * tour's own departure; with neither, every batch and held unit counts.
     */
    private function availableOnDeparture(Option $option, ?DateRange $stay): int
    {
        $departure = $stay->startsOn ?? $this->tourDepartureOf($option);

        if ($departure === null) {
            return max(0, $this->capacityOf($option) - $this->heldUnits($option)->count());
        }

        return $this->calculatorFor($option, DateRange::of($departure, $departure->addDay()))->availableOn($departure);
    }

    private function tourDepartureOf(Option $option): ?CarbonImmutable
    {
        return $option->item?->tourProfile?->departs_on->toImmutable()->startOfDay();
    }

    /**
     * Load what the calculator needs for the range: every live batch, and the
     * units held on each of its nights.
     */
    private function calculatorFor(Option $option, DateRange $range): AvailabilityCalculator
    {
        $batches = array_values($option->inventoryBatches()
            ->get(['quantity', 'effective_from', 'effective_to'])
            ->map(fn (InventoryBatch $batch): array => [
                'quantity' => $batch->quantity,
                'effective_from' => $batch->effective_from?->toImmutable()->startOfDay(),
                'effective_to' => $batch->effective_to?->toImmutable()->startOfDay(),
            ])
            ->all());

        $heldByNight = $this->heldUnits($option)
            ->where('occupied_on', '>=', $range->startsOn->toDateString())
            ->where('occupied_on', '<', $range->endsOn->toDateString())
            ->groupBy('occupied_on')
            ->selectRaw('occupied_on, count(*) as held')
            ->pluck('held', 'occupied_on')
            ->mapWithKeys(fn (mixed $held, string $night): array => [substr($night, 0, 10) => (int) $held])
            ->all();

        return new AvailabilityCalculator($batches, $heldByNight);
    }

    /**
     * The option's allocation rows, one per unit held on a date.
     */
    private function heldUnits(Option $option): Builder
    {
        return $option->getConnection()->table('inventory_allocations')->where('option_id', $option->id);
    }

    /**
     * @throws LogicException
     */
    private function stayFor(Option $option, ?DateRange $stay): DateRange
    {
        return $stay ?? throw new LogicException("Option [{$option->id}] is sold by the night; its availability depends on the nights asked for.");
    }

    /**
     * @throws InvalidArgumentException
     */
    private function ensureStayLength(Option $option, DateRange $stay): void
    {
        $nights = $stay->nights();

        if (($option->min_nights !== null && $nights < $option->min_nights) || ($option->max_nights !== null && $nights > $option->max_nights)) {
            throw new InvalidArgumentException("[{$option->name}] is booked for {$option->min_nights} to {$option->max_nights} nights, not {$nights}.");
        }
    }
}
