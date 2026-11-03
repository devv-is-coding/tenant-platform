<?php

namespace App\Services\Inventory;

use App\ValueObjects\DateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The availability arithmetic for one option, night by night, with no
 * database: AvailabilityService loads the rows, this does the sums.
 *
 *     capacity(night)  = the batches whose effective range covers the night
 *     held(night)      = the units bookings hold that night, one row each
 *     available(night) = capacity - held, never below zero
 *
 * A batch's effective range includes both of its dates, and an open end is
 * unbounded, so "three extra rooms, December only" is 1 to 31 December. A
 * stay's nights are half-open (DateRange): the check-out day is never one of
 * them, so back-to-back stays never compete for a night.
 */
final readonly class AvailabilityCalculator
{
    /**
     * @param  list<array{quantity: int, effective_from: CarbonImmutable|null, effective_to: CarbonImmutable|null}>  $batches
     * @param  array<string, int>  $heldByNight  units held, keyed by the night as Y-m-d
     */
    public function __construct(private array $batches, private array $heldByNight) {}

    /**
     * Get the units the ledger provides on the night.
     */
    public function capacityOn(CarbonImmutable $night): int
    {
        $capacity = 0;

        foreach ($this->batches as $batch) {
            $started = $batch['effective_from'] === null || $night->greaterThanOrEqualTo($batch['effective_from']);
            $notEnded = $batch['effective_to'] === null || $night->lessThanOrEqualTo($batch['effective_to']);

            if ($started && $notEnded) {
                $capacity += $batch['quantity'];
            }
        }

        return $capacity;
    }

    /**
     * Get the units bookings hold on the night.
     */
    public function heldOn(CarbonImmutable $night): int
    {
        return $this->heldByNight[$night->toDateString()] ?? 0;
    }

    /**
     * Get how many more units can be sold on the night.
     */
    public function availableOn(CarbonImmutable $night): int
    {
        return max(0, $this->capacityOn($night) - $this->heldOn($night));
    }

    /**
     * Get how many units can be sold for the whole stay: the fewest left on
     * any one of its nights, since a guest needs the same room every night.
     *
     * @throws InvalidArgumentException for a stay with no nights
     */
    public function availableFor(DateRange $stay): int
    {
        return min(array_map($this->availableOn(...), $this->nightsOf($stay)));
    }

    /**
     * Get the stay night by night, for a calendar.
     *
     * @return list<array{night: CarbonImmutable, capacity: int, held: int, available: int}>
     *
     * @throws InvalidArgumentException for a stay with no nights
     */
    public function breakdown(DateRange $stay): array
    {
        return array_map(fn (CarbonImmutable $night): array => [
            'night' => $night,
            'capacity' => $this->capacityOn($night),
            'held' => $this->heldOn($night),
            'available' => $this->availableOn($night),
        ], $this->nightsOf($stay));
    }

    /**
     * @return non-empty-list<CarbonImmutable>
     *
     * @throws InvalidArgumentException
     */
    private function nightsOf(DateRange $stay): array
    {
        $nights = $stay->eachNight();

        if ($nights === []) {
            throw new InvalidArgumentException("A stay from {$stay->startsOn->toDateString()} to {$stay->endsOn->toDateString()} has no nights.");
        }

        return $nights;
    }
}
