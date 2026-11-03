<?php

use App\Enums\AuditAction;
use App\Enums\BookingApprovalStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Enums\Role;
use App\Exceptions\OptionUnavailableException;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\InventoryAllocation;
use App\Models\Item;
use App\Models\Option;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingDraftLine;
use App\Services\Booking\BookingService;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

test('defect 4: one booking holds a tour seat and a visa, each its own line', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $booked = withinTenant($tenant, function () use ($customer): array {
        $seat = seatOnTour();
        $visa = bookableVisa();

        $booking = bookFor($customer, [new BookingDraftLine($seat->id), new BookingDraftLine($visa->id)]);

        return [
            'lines' => $booking->lines()->pluck('item_type')->all(),
            'total' => $booking->fresh()?->total,
            'approval' => $booking->fresh()?->approval_status,
            'payment' => $booking->fresh()?->payment_status,
        ];
    });

    expect($booked['lines'])->toBe([ItemType::Tour, ItemType::Visa])
        ->and($booked['total']?->toDecimal())->toBe('949.00')
        ->and($booked['approval'])->toBe(BookingApprovalStatus::Pending)
        ->and($booked['payment'])->toBe(BookingPaymentStatus::Unpaid);
});

test('the line totals and the booking total reconcile exactly', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $amounts = withinTenant($tenant, function () use ($customer): array {
        $booking = bookFor($customer, [
            new BookingDraftLine(bookableRoom(3)->id, quantity: 2, stay: stay('2026-12-05', '2026-12-08')),
            new BookingDraftLine(seatOnTour()->id, quantity: 2),
        ]);

        return [
            'lines' => $booking->lines()->pluck('line_total_minor')->all(),
            'subtotal' => DB::table('bookings')->where('id', $booking->id)->value('subtotal_minor'),
            'total' => DB::table('bookings')->where('id', $booking->id)->value('total_minor'),
            'currency' => DB::table('bookings')->where('id', $booking->id)->value('currency'),
        ];
    });

    // Two rooms for three nights at 199.00, and two seats at 499.00.
    expect($amounts['lines'])->toBe([119400, 99800])
        ->and((int) $amounts['subtotal'])->toBe(219200)
        ->and((int) $amounts['total'])->toBe(219200)
        ->and($amounts['currency'])->toBe('PHP');
});

test('a line keeps the name, type, mode and price it was sold at after the catalog changes', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $line = withinTenant($tenant, function () use ($customer): array {
        $seat = seatOnTour();
        $booking = bookFor($customer, [new BookingDraftLine($seat->id)]);

        $seat->item?->update(['name' => 'Japan Grand Tour']);
        $seat->update(['name' => 'Solo traveller', 'price' => Money::parse('599.00', 'PHP')]);

        return $booking->lines()->sole()->only(['item_name', 'option_name', 'unit_price_minor', 'line_total_minor']);
    });

    expect($line)->toBe(['item_name' => 'Japan Discovery', 'option_name' => 'Solo', 'unit_price_minor' => 49900, 'line_total_minor' => 49900]);
});

test('a room holds each night of its stay, a tour seat its departure date, and a visa nothing', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $held = withinTenant($tenant, function () use ($customer): array {
        $booking = bookFor($customer, [
            new BookingDraftLine(bookableRoom(3)->id, quantity: 2, stay: stay('2026-12-05', '2026-12-08')),
            new BookingDraftLine(seatOnTour()->id, quantity: 3),
            new BookingDraftLine(bookableVisa()->id),
        ]);

        return $booking->lines()->get()->map(fn ($line): array => [
            'nights' => $line->nights,
            'starts_on' => $line->starts_on?->toDateString(),
            'ends_on' => $line->ends_on?->toDateString(),
            'rows' => DB::table('inventory_allocations')->where('booking_line_id', $line->id)->orderBy('occupied_on')->pluck('occupied_on')->all(),
        ])->all();
    });

    expect($held)->toBe([
        ['nights' => 3, 'starts_on' => '2026-12-05', 'ends_on' => '2026-12-08', 'rows' => ['2026-12-05', '2026-12-05', '2026-12-06', '2026-12-06', '2026-12-07', '2026-12-07']],
        ['nights' => null, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-12', 'rows' => ['2026-12-01', '2026-12-01', '2026-12-01']],
        ['nights' => null, 'starts_on' => null, 'ends_on' => null, 'rows' => []],
    ]);
});

test('each booking takes the lowest units no one holds, and an option with none left refuses the whole booking', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    withinTenant($tenant, function () use ($customer): void {
        $room = bookableRoom(2);
        $visa = bookableVisa();
        bookFor($customer, [new BookingDraftLine($room->id, stay: stay('2026-12-05', '2026-12-07'))]);
        bookFor($customer, [new BookingDraftLine($room->id, stay: stay('2026-12-06', '2026-12-08'))]);

        expect(fn () => bookFor($customer, [new BookingDraftLine($visa->id), new BookingDraftLine($room->id, stay: stay('2026-12-06', '2026-12-07'))]))
            ->toThrow(OptionUnavailableException::class);

        expect(InventoryAllocation::query()->orderBy('occupied_on')->orderBy('unit_index')->get()->map(fn (InventoryAllocation $row): string => "{$row->occupied_on->toDateString()}#{$row->unit_index}")->all())
            ->toBe(['2026-12-05#0', '2026-12-06#0', '2026-12-06#1', '2026-12-07#0'])
            ->and(Booking::count())->toBe(2)
            ->and(AuditEvent::query()->where('action', AuditAction::BookingCreated)->count())->toBe(2);
    });
});

test('two lines of one room that together need more rooms than there are are refused together', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    withinTenant($tenant, function () use ($customer): void {
        $room = bookableRoom(2);

        expect(fn () => bookFor($customer, [
            new BookingDraftLine($room->id, stay: stay('2026-12-05', '2026-12-07')),
            new BookingDraftLine($room->id, quantity: 2, stay: stay('2026-12-06', '2026-12-08')),
        ]))->toThrow(OptionUnavailableException::class);

        expect(Booking::count())->toBe(0)
            ->and(InventoryAllocation::count())->toBe(0);
    });
});

test('an option of another agency cannot be booked', function () {
    $dumaguete = tenantOnDomain();
    $tanjay = tenantOnDomain();
    $tanjayCustomer = User::factory()->role(Role::Customer)->for($tanjay)->create();
    $dumagueteSeat = withinTenant($dumaguete, fn (): Option => seatOnTour());

    withinTenant($tanjay, function () use ($tanjayCustomer, $dumagueteSeat): void {
        expect(fn () => bookFor($tanjayCustomer, [new BookingDraftLine($dumagueteSeat->id)]))->toThrow(ModelNotFoundException::class);
        expect(Booking::count())->toBe(0);
    });
});

test('only an active customer of the agency can be booked for', function (Closure $makeUser) {
    $tenant = tenantOnDomain();
    $user = $makeUser($tenant);

    withinTenant($tenant, function () use ($user): void {
        $seat = seatOnTour();

        expect(fn () => bookFor($user, [new BookingDraftLine($seat->id)]))
            ->toThrow(InvalidArgumentException::class, "User [{$user->id}] is not an active customer of this agency, so cannot book with it.");
    });
})->with([
    'the agency\'s staff' => [fn (Tenant $tenant): User => User::factory()->role(Role::Staff)->for($tenant)->create()],
    'another agency\'s customer' => [fn (Tenant $tenant): User => User::factory()->role(Role::Customer)->for(tenantOnDomain())->create()],
    'a deactivated customer' => [fn (Tenant $tenant): User => User::factory()->role(Role::Customer)->for($tenant)->inactive()->create()],
]);

test('only an active option on an active, published item is open for booking', function (Closure $close) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    withinTenant($tenant, function () use ($customer, $close): void {
        $seat = seatOnTour();
        $close($seat);

        expect(fn () => bookFor($customer, [new BookingDraftLine($seat->id)]))
            ->toThrow(InvalidArgumentException::class, '[Solo] is not open for booking.');
    });
})->with([
    'an inactive option' => [fn (Option $seat) => $seat->update(['is_active' => false])],
    'an inactive item' => [fn (Option $seat) => $seat->item?->update(['is_active' => false])],
    'an item not yet published' => [fn (Option $seat) => $seat->item?->update(['item_status' => ItemStatus::Pending])],
]);

test('a line\'s dates must fit its option\'s mode', function (Closure $makeLine, string $message) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    withinTenant($tenant, function () use ($customer, $makeLine, $message): void {
        expect(fn () => bookFor($customer, [$makeLine()]))->toThrow(InvalidArgumentException::class, $message);
    });
})->with([
    'a visa for dates' => [fn () => new BookingDraftLine(bookableVisa()->id, stay: stay('2026-12-05', '2026-12-08')), 'is not booked for dates.'],
    'a room without a stay' => [fn () => new BookingDraftLine(bookableRoom()->id), '[Room 101] is booked by the night; give a stay of at least one night.'],
    'a room for no nights' => [fn () => new BookingDraftLine(bookableRoom()->id, stay: stay('2026-12-05', '2026-12-05')), '[Room 101] is booked by the night; give a stay of at least one night.'],
    'a tour seat for dates of its own' => [fn () => new BookingDraftLine(seatOnTour()->id, stay: stay('2026-12-05', '2026-12-08')), '[Solo] departs with its tour on 2026-12-01; it takes no dates of its own.'],
    'a hotel package without a date' => [fn () => new BookingDraftLine(Option::factory()->for(Item::factory()->hotel()->published())->create(['name' => 'New Year package'])->id), '[New Year package] needs the date it is booked for.'],
]);

test('the booking is recorded in the agency\'s log by whoever booked it, under a fresh reference', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    actingAs($customer);

    $recorded = withinTenant($tenant, function () use ($customer): array {
        $booking = bookFor($customer, [new BookingDraftLine(seatOnTour()->id)]);

        return ['booking' => $booking, 'event' => AuditEvent::query()->where('action', AuditAction::BookingCreated)->sole()];
    });

    expect($recorded['booking']->reference)->toMatch('/^[A-Z]{1,4}-'.now()->format('Y').'-[A-HJ-NP-Z2-9]{6}$/')
        ->and($recorded['booking']->customer_name)->toBe($customer->full_name)
        ->and($recorded['booking']->created_by)->toBe($customer->id)
        ->and($recorded['event']->actor_id)->toBe($customer->id)
        ->and($recorded['event']->subject_type)->toBe('booking')
        ->and($recorded['event']->subject_id)->toBe($recorded['booking']->id);
});

/**
 * Play another booking that takes unit 0 on the tour's departure date just
 * after the allocator counted it free, inside the same transaction, the given
 * number of times. The allocator's insert then hits the unique index, as the
 * loser of a real race would.
 *
 * @return object{taken: int}
 */
function takeUnitZeroWhileAllocating(Option $seat, int $times): object
{
    $counter = new class
    {
        public int $taken = 0;
    };

    DB::listen(function (QueryExecuted $query) use ($seat, $times, $counter): void {
        $countsFreeUnits = str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'inventory_allocations')
            && str_contains($query->sql, 'unit_index');

        if (! $countsFreeUnits || $counter->taken >= $times) {
            return;
        }

        $counter->taken++;
        $line = DB::table('booking_lines')->orderByDesc('id')->first();
        DB::table('inventory_allocations')->insert(['option_id' => $seat->id, 'booking_id' => $line->booking_id, 'booking_line_id' => $line->id, 'occupied_on' => '2026-12-01', 'unit_index' => 0]);
    });

    return $counter;
}

test('a unit taken between counting and writing is counted again, and the booking still goes through', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    $outcome = withinTenant($tenant, function () use ($customer): array {
        $seat = seatOnTour(stock: 2);
        $race = takeUnitZeroWhileAllocating($seat, times: 1);

        bookFor($customer, [new BookingDraftLine($seat->id)]);

        return [
            'taken' => $race->taken,
            'units' => InventoryAllocation::query()->pluck('unit_index')->all(),
            'bookings' => Booking::count(),
            'recorded' => AuditEvent::query()->where('action', AuditAction::BookingCreated)->count(),
        ];
    });

    // The rival's row rolled back with the failed attempt; the retry took unit 0 itself.
    expect($outcome)->toBe(['taken' => 1, 'units' => [0], 'bookings' => 1, 'recorded' => 1]);
});

test('after colliding on every attempt the booking is refused as unavailable, leaving nothing behind', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();

    withinTenant($tenant, function () use ($customer): void {
        $seat = seatOnTour(stock: 2);
        $race = takeUnitZeroWhileAllocating($seat, times: BookingService::MAX_ATTEMPTS);

        expect(fn () => bookFor($customer, [new BookingDraftLine($seat->id)]))
            ->toThrow(OptionUnavailableException::class);

        expect($race->taken)->toBe(BookingService::MAX_ATTEMPTS)
            ->and(Booking::count())->toBe(0)
            ->and(InventoryAllocation::count())->toBe(0)
            ->and(AuditEvent::query()->where('action', AuditAction::BookingCreated)->count())->toBe(0);
    });
});

test('a booking holds its units for the agency\'s hold time', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    travelTo('2026-11-03 10:00:00');

    $expiresAt = withinTenant($tenant, function () use ($customer): ?string {
        Settings::current()->update(['booking_hold_minutes' => 90]);

        return bookFor($customer, [new BookingDraftLine(seatOnTour()->id)])->expires_at?->toDateTimeString();
    });

    expect($expiresAt)->toBe('2026-11-03 11:30:00');
});
