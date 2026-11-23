<?php

use App\Enums\AuditAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use App\Services\Review\ReviewService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * A completed booking, paid in full, for the customer on a new published
 * tour, with the tour. Call inside the agency.
 *
 * @param  array<string, mixed>  $state
 * @return array{0: Booking, 1: Item}
 */
function reviewableBooking(User $customer, array $state = []): array
{
    $seat = seatOnTour();
    $booking = Booking::factory()->approved()->paid()->completed()->create(['customer_id' => $customer->id, 'reference' => 'DGTE-2026-0002', ...$state]);
    BookingLine::factory()->for($booking)->create(['option_id' => $seat->id]);

    return [$booking, Item::query()->findOrFail($seat->item_id)];
}

/**
 * The item's rating as it reads after the call, inside the agency.
 *
 * @return array{0: string|null, 1: int}
 */
function ratingOf(Item $item): array
{
    $item->refresh();

    return [$item->rating_avg, $item->reviews_count];
}

test('a customer reviews a completed booking paid in full, and the item\'s rating counts it', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create(['first_name' => 'Beatriz', 'middle_name' => null, 'last_name' => 'Alonzo']);
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($customer));

    withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, 5, ' Worth every peso ', 'Hokkaido in December was extraordinary.'));

    $after = withinTenant($tenant, fn (): array => [
        'review' => Review::query()->sole()->only(['booking_id', 'item_id', 'customer_id', 'customer_name', 'title', 'content', 'rating', 'is_approved', 'is_featured']),
        'rating' => ratingOf($item),
        'logged' => AuditEvent::query()->where('action', AuditAction::ReviewCreated)->pluck('description')->all(),
    ]);
    expect($after['review'])->toBe([
        'booking_id' => $booking->id,
        'item_id' => $item->id,
        'customer_id' => $customer->id,
        'customer_name' => 'Beatriz Alonzo',
        'title' => 'Worth every peso',
        'content' => 'Hokkaido in December was extraordinary.',
        'rating' => 5,
        'is_approved' => true,
        'is_featured' => false,
    ])
        ->and($after['rating'])->toBe(['5.00', 1])
        ->and($after['logged'])->toBe(['Japan Discovery reviewed, 5 of 5, on DGTE-2026-0002.']);
});

test('a booking not yet completed, or not paid in full, cannot be reviewed', function (array $state, string $message) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($customer, $state));

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, 5)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): array => [Review::query()->count(), ...ratingOf($item)]))->toBe([0, null, 0]);
})->with([
    'checked in, not completed' => [['fulfilment_status' => FulfilmentStatus::CheckedIn], 'DGTE-2026-0002 can be reviewed once it is completed.'],
    'a no-show' => [['fulfilment_status' => FulfilmentStatus::NoShow], 'DGTE-2026-0002 can be reviewed once it is completed.'],
    'partly paid' => [['amount_paid_minor' => 49800, 'payment_status' => BookingPaymentStatus::PartiallyPaid], 'DGTE-2026-0002 can be reviewed once it is paid in full.'],
]);

test('a booking paid in full can still be reviewed after a partial refund', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($customer, [
        'amount_refunded_minor' => 5000,
        'payment_status' => BookingPaymentStatus::PartiallyRefunded,
    ]));

    withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, 4));

    expect(withinTenant($tenant, fn (): array => ratingOf($item)))->toBe(['4.00', 1]);
});

test('a customer cannot review someone else\'s booking', function () {
    $tenant = tenantOnDomain();
    [$customer, $other] = User::factory()->count(2)->role(Role::Customer)->for($tenant)->create()->all();
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($other));

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, 1)))
        ->toThrow(ValidationException::class, 'That booking is not yours.');

    expect(withinTenant($tenant, fn (): int => Review::query()->count()))->toBe(0);
});

test('a review must be of an item on the booking', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $elsewhere] = withinTenant($tenant, function () use ($customer): array {
        [$booking] = reviewableBooking($customer);

        return [$booking, Item::factory()->published()->create(['name' => 'Seoul Highlights'])];
    });

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $elsewhere, $customer, 5)))
        ->toThrow(ValidationException::class, 'Seoul Highlights is not on DGTE-2026-0002.');

    expect(withinTenant($tenant, fn (): int => Review::query()->count()))->toBe(0);
});

test('a booking is reviewed once, even after its review was deleted', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($customer));
    withinTenant($tenant, function () use ($booking, $item, $customer): void {
        $reviews = app(ReviewService::class);
        $reviews->delete($reviews->submit($booking, $item, $customer, 2));
    });

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, 5)))
        ->toThrow(ValidationException::class, 'DGTE-2026-0002 is already reviewed.');

    expect(withinTenant($tenant, fn (): array => [Review::withTrashed()->count(), ...ratingOf($item)]))->toBe([1, null, 0]);
});

test('the database refuses a second review of one booking by its customer', function () {
    $tenant = tenantOnDomain();
    $booking = withinTenant($tenant, fn (): Booking => Booking::factory()->create());
    withinTenant($tenant, fn (): Review => Review::factory()->for($booking)->create());

    expect(fn (): Review => withinTenant($tenant, fn (): Review => Review::factory()->for($booking)->create()))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a rating must be from 1 to 5, and a title at most 100 characters', function (int $rating, string $title, string $message) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$booking, $item] = withinTenant($tenant, fn (): array => reviewableBooking($customer));

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->submit($booking, $item, $customer, $rating, $title)))
        ->toThrow(ValidationException::class, $message);

    expect(withinTenant($tenant, fn (): int => Review::query()->count()))->toBe(0);
})->with([
    'no stars' => [0, 'Fine', 'Give a rating from 1 to 5.'],
    'six stars' => [6, 'Fine', 'Give a rating from 1 to 5.'],
    'a title of 101 characters' => [5, str_repeat('a', 101), 'Keep the title to 100 characters.'],
]);

test('the item\'s rating drops a review that is hidden or deleted', function (Closure $moderate) {
    $tenant = tenantOnDomain();
    [$item, $threeStars] = withinTenant($tenant, function (): array {
        $item = Item::factory()->published()->create();
        Review::factory()->for($item)->create(['rating' => 5]);
        $threeStars = Review::factory()->for($item)->create(['rating' => 3]);
        $item->recomputeRating();

        return [$item, $threeStars];
    });

    withinTenant($tenant, fn (): mixed => $moderate(app(ReviewService::class), $threeStars));

    expect(withinTenant($tenant, fn (): array => ratingOf($item)))->toBe(['5.00', 1]);
})->with([
    'hidden' => [fn (ReviewService $reviews, Review $review): Review => $reviews->reject($review)],
    'deleted' => [fn (ReviewService $reviews, Review $review) => $reviews->delete($review)],
]);

test('the item\'s rating counts a hidden review again once it is approved', function () {
    $tenant = tenantOnDomain();
    [$item, $hidden] = withinTenant($tenant, function (): array {
        $item = Item::factory()->published()->create();
        Review::factory()->for($item)->create(['rating' => 5]);
        $hidden = Review::factory()->hidden()->for($item)->create(['rating' => 2]);
        $item->recomputeRating();

        return [$item, $hidden];
    });

    withinTenant($tenant, fn (): Review => app(ReviewService::class)->approve($hidden));

    expect(withinTenant($tenant, fn (): array => ratingOf($item)))->toBe(['3.50', 2]);
});

test('the rating keeps two decimals, and goes back to none when no review counts', function () {
    $tenant = tenantOnDomain();
    [$item, $reviews] = withinTenant($tenant, function (): array {
        $item = Item::factory()->published()->create();
        $reviews = Review::factory()->count(3)->for($item)->sequence(['rating' => 5], ['rating' => 4], ['rating' => 4])->create();
        $item->recomputeRating();

        return [$item, $reviews];
    });
    $thirds = withinTenant($tenant, fn (): array => ratingOf($item));

    withinTenant($tenant, fn () => $reviews->each(fn (Review $review) => app(ReviewService::class)->reject($review)));

    expect($thirds)->toBe(['4.33', 3])
        ->and(withinTenant($tenant, fn (): array => ratingOf($item)))->toBe([null, 0]);
});

test('staff feature a showing review, and can stop featuring it', function () {
    $tenant = tenantOnDomain();
    actingAsTenantUser($tenant, Role::Manager);
    $review = withinTenant($tenant, fn (): Review => Review::factory()->for(Item::factory()->published()->create(['name' => 'Japan Discovery']))->create());

    $featured = withinTenant($tenant, fn (): bool => app(ReviewService::class)->feature($review)->is_featured);
    $unfeatured = withinTenant($tenant, fn (): bool => app(ReviewService::class)->unfeature($review)->is_featured);

    expect([$featured, $unfeatured])->toBe([true, false])
        ->and(withinTenant($tenant, fn (): array => AuditEvent::query()
            ->whereIn('action', [AuditAction::ReviewFeatured, AuditAction::ReviewUnfeatured])
            ->orderBy('id')->pluck('description')->all()))
        ->toBe(['Review of Japan Discovery featured.', 'Review of Japan Discovery no longer featured.']);
});

test('a hidden review cannot be featured, and hiding a featured review unfeatures it', function () {
    $tenant = tenantOnDomain();
    [$hidden, $featured] = withinTenant($tenant, fn (): array => [
        Review::factory()->hidden()->create(),
        Review::factory()->create(['is_featured' => true]),
    ]);

    expect(fn (): Review => withinTenant($tenant, fn (): Review => app(ReviewService::class)->feature($hidden)))
        ->toThrow(ValidationException::class, 'Show the review before featuring it.');

    expect(withinTenant($tenant, fn (): array => app(ReviewService::class)->reject($featured)->only(['is_approved', 'is_featured'])))
        ->toBe(['is_approved' => false, 'is_featured' => false]);
});
