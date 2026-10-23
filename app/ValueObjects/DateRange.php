<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/**
 * A stay from one calendar date to another, read as nights.
 *
 * Half-open: [startsOn, endsOn). The day you leave is not a night you used,
 * so a guest checking out on the 5th and another checking in on the 5th never
 * overlap. An inverted range cannot be built at all, which is what the legacy
 * lacked when it stored a tour ending in the year 0026 (defect 3). The same
 * start and end make an empty stay with no nights.
 *
 * Compare stay dates through this class, never by hand.
 */
final readonly class DateRange
{
    private function __construct(public CarbonImmutable $startsOn, public CarbonImmutable $endsOn) {}

    /**
     * Create a range from two dates, as dates or "Y-m-d" strings. Any time of
     * day is dropped.
     *
     * @throws InvalidArgumentException
     */
    public static function of(CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): self
    {
        $start = self::toDate($startsOn);
        $end = self::toDate($endsOn);

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException("A range cannot end ({$end->toDateString()}) before it starts ({$start->toDateString()}).");
        }

        return new self($start, $end);
    }

    /**
     * Count the nights: the check-out day is not one of them.
     */
    public function nights(): int
    {
        return (int) $this->startsOn->diffInDays($this->endsOn);
    }

    /**
     * List the date of each night, from the first night to the night before
     * check-out.
     *
     * @return list<CarbonImmutable>
     */
    public function eachNight(): array
    {
        $nights = [];

        for ($night = $this->startsOn; $night->lessThan($this->endsOn); $night = $night->addDay()) {
            $nights[] = $night;
        }

        return $nights;
    }

    /**
     * Determine whether the two stays share at least one night. Back-to-back
     * stays do not, and neither does an empty stay.
     */
    public function overlaps(self $other): bool
    {
        return $this->nights() > 0
            && $other->nights() > 0
            && $this->startsOn->lessThan($other->endsOn)
            && $other->startsOn->lessThan($this->endsOn);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function toDate(CarbonInterface|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::parse($date->toDateString())->startOfDay();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        // A date that rolls over, such as 2026-02-30 becoming March 2nd, reads back differently.
        if ($parsed === null || $parsed->toDateString() !== $date) {
            throw new InvalidArgumentException("[{$date}] is not a date in the Y-m-d format.");
        }

        return $parsed;
    }
}
