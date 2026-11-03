<?php

namespace App\Services\Booking;

use App\Exceptions\OptionUnavailableException;
use App\Models\BookingLine;
use App\Models\Option;
use App\Services\Inventory\AvailabilityService;
use App\ValueObjects\DateRange;
use Carbon\CarbonImmutable;

/**
 * Writes the units a booking line holds: one allocation row per unit per
 * date, each naming which of the option's units it takes (`unit_index`).
 *
 * On each date the option has as many units as its ledger provides there
 * (indices 0 to capacity − 1); a line takes the lowest ones no row already
 * holds. UNIQUE(option_id, occupied_on, unit_index) refuses a second holder of
 * the same unit, which is what makes overselling impossible even when two
 * bookings race: the loser's insert fails instead of overselling.
 */
class AllocationService
{
    public function __construct(private AvailabilityService $availability) {}

    /**
     * Hold the line's quantity of the option on each of the dates.
     *
     * @param  non-empty-list<CarbonImmutable>  $dates  consecutive, earliest first
     *
     * @throws OptionUnavailableException when a date has fewer free units than the line needs
     */
    public function allocate(BookingLine $line, Option $option, array $dates): void
    {
        $freeByDate = $this->freeUnits($option, $dates);
        $fewestFree = min(array_map(count(...), $freeByDate));

        if ($fewestFree < $line->quantity) {
            throw new OptionUnavailableException($option, $line->quantity, $fewestFree);
        }

        $now = now();
        $rows = [];

        foreach ($freeByDate as $date => $free) {
            foreach (array_slice($free, 0, $line->quantity) as $unitIndex) {
                $rows[] = [
                    'option_id' => $option->id,
                    'booking_id' => $line->booking_id,
                    'booking_line_id' => $line->id,
                    'occupied_on' => $date,
                    'unit_index' => $unitIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $option->getConnection()->table('inventory_allocations')->insert($rows);
    }

    /**
     * The unit indices no row holds yet on each date, lowest first.
     *
     * @param  non-empty-list<CarbonImmutable>  $dates
     * @return non-empty-array<string, list<int>> keyed by the date as Y-m-d
     */
    private function freeUnits(Option $option, array $dates): array
    {
        $range = DateRange::of($dates[0], $dates[count($dates) - 1]->addDay());

        $capacity = [];

        foreach ($this->availability->nightlyBreakdown($option, $range) as $night) {
            $capacity[$night['night']->toDateString()] = $night['capacity'];
        }

        $held = [];

        $rows = $option->getConnection()->table('inventory_allocations')
            ->where('option_id', $option->id)
            ->where('occupied_on', '>=', $range->startsOn->toDateString())
            ->where('occupied_on', '<', $range->endsOn->toDateString())
            ->get(['occupied_on', 'unit_index']);

        foreach ($rows as $row) {
            $held[substr((string) $row->occupied_on, 0, 10)][] = (int) $row->unit_index;
        }

        $free = [];

        foreach ($dates as $date) {
            $day = $date->toDateString();
            $units = ($capacity[$day] ?? 0) > 0 ? range(0, $capacity[$day] - 1) : [];
            $free[$day] = array_values(array_diff($units, $held[$day] ?? []));
        }

        return $free;
    }
}
