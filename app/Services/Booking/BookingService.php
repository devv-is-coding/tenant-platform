<?php

namespace App\Services\Booking;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\AvailabilityMode;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Enums\Role;
use App\Exceptions\OptionUnavailableException;
use App\Models\Booking;
use App\Models\Option;
use App\Models\Settings;
use App\Models\User;
use App\Services\Inventory\AvailabilityService;
use App\ValueObjects\DateRange;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Turns a BookingDraft into a booking: its lines, the units they hold, and the
 * audit record, all in one transaction, so a booking is either written whole
 * or not at all.
 *
 * Each line is checked first: the option is looked up inside this agency and
 * must be open for booking (active, on an active published item); its dates
 * follow its mode (a tour departs on its own dates, a room needs the stay, a
 * visa takes none); and the ledger must cover it. Prices come from the option
 * now and are snapshotted on the line.
 */
class BookingService
{
    use RecordsAuditEvents;

    /**
     * How many times a booking is tried when another took a unit it counted on.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * The characters a reference is made of: no 0/O or 1/I to misread.
     */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private AvailabilityService $availability,
        private AllocationService $allocations,
    ) {}

    /**
     * Book the draft for its customer, holding its units until the agency's
     * hold time runs out (`booking_hold_minutes`) unless it is confirmed.
     *
     * **The unique index is the guarantee; the row lock is a courtesy.**
     * UNIQUE(option_id, occupied_on, unit_index) refuses a second holder of a
     * unit on every driver, so two bookings racing for the last unit can never
     * both get it. The lock on each option's row (taken in id order, so two
     * bookings never deadlock each other) makes the race a wait on MariaDB
     * instead of a collision; on SQLite, where the tests run, `lockForUpdate()`
     * compiles to nothing (F1), and the index alone holds. A collision rolls the
     * whole attempt back and books again with the units recounted, up to
     * MAX_ATTEMPTS times, then reports the option unavailable.
     *
     * @throws InvalidArgumentException for a customer, option, quantity or dates the booking cannot take
     * @throws ModelNotFoundException for an option this agency does not have
     * @throws OptionUnavailableException when the ledger cannot cover a line
     */
    public function create(BookingDraft $draft): Booking
    {
        $this->ensureIsAgencyCustomer($draft->customer);

        for ($attempt = 1; ; $attempt++) {
            $allocating = null;

            try {
                return DB::transaction(function () use ($draft, &$allocating): Booking {
                    return $this->write($draft, $allocating);
                }, attempts: self::MAX_ATTEMPTS);
            } catch (UniqueConstraintViolationException $collision) {
                // Only a unit collision is retried; any other unique key is a bug to see.
                if ($allocating === null) {
                    throw $collision;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    $available = $this->availability->availableFor($allocating['option'], $allocating['stay']) ?? 0;

                    throw new OptionUnavailableException($allocating['option'], $allocating['quantity'], $available);
                }
            }
        }
    }

    /**
     * Release a hold nobody confirmed in time: a booking still awaiting the
     * manager, unpaid, past `expires_at`. It becomes Expired and its units are
     * hard-deleted, free to sell again. Checked again under a lock, so a
     * booking approved or paid meanwhile is left alone.
     *
     * An approved booking never expires, and neither does one with money
     * against it (`scenario_story.md`, "What writing this exposed" #4).
     *
     * @return bool whether the booking expired
     */
    public function expire(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->isLapsedHold($locked)) {
                return false;
            }

            $locked->forceFill(['approval_status' => BookingApprovalStatus::Expired])->save();
            $released = $locked->allocations()->delete();

            $this->recordAuditEvent(
                AuditAction::BookingExpired,
                $locked,
                __(':reference expired unconfirmed; :units held units released.', ['reference' => $locked->reference, 'units' => $released]),
                ['approval_status' => ['old' => BookingApprovalStatus::Pending->value, 'new' => BookingApprovalStatus::Expired->value]],
            );

            return true;
        });
    }

    /**
     * Determine if the booking is a hold whose time ran out unconfirmed and unpaid.
     */
    public function isLapsedHold(Booking $booking): bool
    {
        return $booking->approval_status === BookingApprovalStatus::Pending
            && $booking->payment_status === BookingPaymentStatus::Unpaid
            && $booking->expires_at !== null
            && $booking->expires_at->lessThanOrEqualTo(now());
    }

    /**
     * One attempt at writing the booking, inside its transaction. `$allocating`
     * names the line whose units are being written, so a collision can be
     * told apart from any other unique key, and reported.
     *
     * @param  array{option: Option, quantity: int, stay: DateRange|null}|null  $allocating
     *
     * @throws InvalidArgumentException|ModelNotFoundException|OptionUnavailableException
     */
    private function write(BookingDraft $draft, ?array &$allocating): Booking
    {
        $optionIds = array_values(array_unique(array_map(fn (BookingDraftLine $line): int => $line->optionId, $draft->lines)));
        sort($optionIds);
        Option::query()->whereKey($optionIds)->orderBy('id')->lockForUpdate()->get(['id']);

        $lines = array_map($this->planLine(...), $draft->lines);
        $currency = $this->agencyCurrency();

        $subtotal = array_reduce(
            $lines,
            fn (Money $sum, array $line): Money => $sum->plus($line['total']),
            Money::zero($currency),
        );

        $booking = Booking::create([
            'reference' => $this->newReference(),
            'customer_id' => $draft->customer->id,
            'customer_name' => $draft->customer->full_name,
            'customer_email' => $draft->customer->email,
            'subtotal' => $subtotal,
            'discount' => Money::zero($currency),
            'total' => $subtotal,
            'expires_at' => now()->addMinutes(Settings::current()->booking_hold_minutes),
            'created_by' => ($draft->createdBy ?? $draft->customer)->id,
        ]);

        foreach ($lines as $line) {
            $bookingLine = $booking->lines()->create($line['attributes']);

            if ($line['dates'] !== []) {
                $allocating = ['option' => $line['option'], 'quantity' => $bookingLine->quantity, 'stay' => $line['stay']];
                $this->allocations->allocate($bookingLine, $line['option'], $line['dates']);
                $allocating = null;
            }
        }

        $this->recordAuditEvent(AuditAction::BookingCreated, $booking, __(':reference booked for :customer.', [
            'reference' => $booking->reference,
            'customer' => $booking->customer_name,
        ]));

        return $booking;
    }

    /**
     * Check one line and work out what it holds and costs.
     *
     * @return array{option: Option, dates: list<CarbonImmutable>, stay: DateRange|null, total: Money, attributes: array<string, mixed>}
     *
     * @throws InvalidArgumentException|ModelNotFoundException|OptionUnavailableException
     */
    private function planLine(BookingDraftLine $line): array
    {
        $option = Option::query()->with('item.tourProfile')->findOrFail($line->optionId);
        $item = $option->item;

        if ($item === null || ! $option->is_active || ! $item->is_active || $item->item_status !== ItemStatus::Published) {
            throw new InvalidArgumentException("[{$option->name}] is not open for booking.");
        }

        [$startsOn, $endsOn, $dates, $stayToCheck] = $this->datesFor($option, $line->stay);

        $this->availability->assertCanAllocate($option, $line->quantity, $stayToCheck);

        $nights = $option->availability_mode === AvailabilityMode::Nightly ? count($dates) : null;
        $total = $option->price->times($line->quantity * ($nights ?? 1));

        return [
            'option' => $option,
            'dates' => $dates,
            'stay' => $stayToCheck,
            'total' => $total,
            'attributes' => [
                'option_id' => $option->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'option_name' => $option->name,
                'item_type' => $item->item_type,
                'availability_mode' => $option->availability_mode,
                'unit_price_minor' => $option->price->minor,
                'quantity' => $line->quantity,
                'nights' => $nights,
                'starts_on' => $startsOn?->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'line_total_minor' => $total->minor,
                'adults' => $line->adults,
                'kids' => $line->kids,
            ],
        ];
    }

    /**
     * The line's dates by the option's mode: the dates shown on the line, the
     * dates it holds units on, and the stay to check availability for.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null, 2: list<CarbonImmutable>, 3: DateRange|null}
     *
     * @throws InvalidArgumentException
     */
    private function datesFor(Option $option, ?DateRange $stay): array
    {
        $item = $option->item;

        if ($option->availability_mode === AvailabilityMode::Unlimited) {
            if ($stay !== null) {
                throw new InvalidArgumentException("[{$option->name}] is not booked for dates.");
            }

            return [null, null, [], null];
        }

        if ($option->availability_mode === AvailabilityMode::Nightly) {
            if ($stay === null || $stay->nights() === 0) {
                throw new InvalidArgumentException("[{$option->name}] is booked by the night; give a stay of at least one night.");
            }

            return [$stay->startsOn, $stay->endsOn, $stay->eachNight(), $stay];
        }

        if ($item?->item_type === ItemType::Tour) {
            $departure = $item->tourProfile ?? throw new InvalidArgumentException("[{$item->name}] has no departure dates yet.");

            if ($stay !== null) {
                throw new InvalidArgumentException("[{$option->name}] departs with its tour on {$departure->departs_on->toDateString()}; it takes no dates of its own.");
            }

            $departsOn = $departure->departs_on->toImmutable()->startOfDay();

            return [$departsOn, $departure->returns_on->toImmutable()->startOfDay(), [$departsOn], DateRange::of($departsOn, $departsOn)];
        }

        if ($stay === null) {
            throw new InvalidArgumentException("[{$option->name}] needs the date it is booked for.");
        }

        return [$stay->startsOn, $stay->endsOn, [$stay->startsOn], $stay];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function ensureIsAgencyCustomer(User $customer): void
    {
        if ($customer->role !== Role::Customer || $customer->tenant_id !== tenant()?->getTenantKey() || ! $customer->is_active) {
            throw new InvalidArgumentException("User [{$customer->id}] is not an active customer of this agency, so cannot book with it.");
        }
    }

    /**
     * A new reference no booking has had, deleted ones included: the agency's
     * initials, the year, and six characters, such as DUMA-2026-K7Q3XZ. Random
     * rather than counted, so two bookings made at once cannot draw the same.
     */
    private function newReference(): string
    {
        $initials = strtoupper(substr((string) preg_replace('/[^a-z]/i', '', (string) tenant()?->getTenantKey()), 0, 4));
        $prefix = ($initials === '' ? 'BK' : $initials).'-'.now()->format('Y').'-';

        do {
            $suffix = '';

            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
        } while (Booking::withTrashed()->where('reference', $prefix.$suffix)->exists());

        return $prefix.$suffix;
    }

    /**
     * @throws LogicException
     */
    private function agencyCurrency(): string
    {
        $currency = tenant('currency');

        if (! is_string($currency)) {
            throw new LogicException('A booking needs an agency with a currency set.');
        }

        return $currency;
    }
}
