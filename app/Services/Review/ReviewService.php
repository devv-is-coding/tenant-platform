<?php

namespace App\Services\Review;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Models\Booking;
use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking-gated reviews, ported from the legacy `feedbacks` (P19): a customer
 * reviews an item on their own booking once the guest was served and the
 * booking paid in full, once per booking. Approved on arrival, faithful to
 * the legacy; staff moderate after the fact (TM-4): hide, approve again,
 * feature, delete.
 *
 * **The item's rating is always true.** Every write that changes which
 * reviews count (a new one, hidden, approved again, deleted) recomputes the
 * item's `rating_avg` and `reviews_count` from source, inside the same
 * transaction, with the item's row locked so two at once cannot both write
 * a stale figure. Hiding and deleting are the easy half to forget.
 *
 * "Paid in full" counts what came in, not what is left: a guest given a
 * goodwill refund afterwards can still review (since TA-20, any refund turns
 * a Paid booking PartiallyRefunded).
 *
 * Who may moderate is for the manager's page and its policy (TM-4); this
 * service keeps the rating true whoever calls it.
 */
class ReviewService
{
    use RecordsAuditEvents;

    /**
     * The longest title the column holds.
     */
    public const MAX_TITLE_LENGTH = 100;

    /**
     * Write the customer's review of an item on their booking.
     *
     * @throws ValidationException for a rating or title out of range, or a booking that cannot be reviewed
     */
    public function submit(Booking $booking, Item $item, User $customer, int $rating, ?string $title = null, ?string $content = null): Review
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => __('Give a rating from 1 to 5.')]);
        }

        $title = $this->clean($title);

        if ($title !== null && mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw ValidationException::withMessages(['title' => __('Keep the title to :max characters.', ['max' => self::MAX_TITLE_LENGTH])]);
        }

        return DB::transaction(function () use ($booking, $item, $customer, $rating, $title, $content): Review {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->ensureReviewable($locked, $item, $customer);
            $lockedItem = $this->lockItem($item->id);

            $review = $locked->review()->create([
                'item_id' => $lockedItem->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'title' => $title,
                'content' => $this->clean($content),
                'rating' => $rating,
            ]);

            $lockedItem->recomputeRating();

            $this->recordAuditEvent(AuditAction::ReviewCreated, $review, __(':item reviewed, :rating of 5, on :reference.', [
                'item' => $lockedItem->name,
                'rating' => $rating,
                'reference' => $locked->reference,
            ]), context: ['booking_id' => $locked->id, 'item_id' => $lockedItem->id]);

            return $review;
        });
    }

    /**
     * Show a hidden review again, so it counts toward the rating.
     *
     * @throws ValidationException for a review already showing
     */
    public function approve(Review $review): Review
    {
        return $this->moderate($review, function (Review $locked): void {
            if ($locked->is_approved) {
                throw ValidationException::withMessages(['review' => __('This review is already showing.')]);
            }

            $locked->forceFill(['is_approved' => true])->save();
        }, AuditAction::ReviewApproved, fn (Item $item): string => __('Review of :item shown again.', ['item' => $item->name]));
    }

    /**
     * Hide a review from customers and from the rating. A featured review
     * stops being featured.
     *
     * @throws ValidationException for a review already hidden
     */
    public function reject(Review $review): Review
    {
        return $this->moderate($review, function (Review $locked): void {
            if (! $locked->is_approved) {
                throw ValidationException::withMessages(['review' => __('This review is already hidden.')]);
            }

            $locked->forceFill(['is_approved' => false, 'is_featured' => false])->save();
        }, AuditAction::ReviewRejected, fn (Item $item): string => __('Review of :item hidden.', ['item' => $item->name]));
    }

    /**
     * Feature a showing review on the item's page.
     *
     * @throws ValidationException for a hidden review, or one already featured
     */
    public function feature(Review $review): Review
    {
        return $this->moderate($review, function (Review $locked): void {
            if (! $locked->is_approved) {
                throw ValidationException::withMessages(['review' => __('Show the review before featuring it.')]);
            }

            if ($locked->is_featured) {
                throw ValidationException::withMessages(['review' => __('This review is already featured.')]);
            }

            $locked->forceFill(['is_featured' => true])->save();
        }, AuditAction::ReviewFeatured, fn (Item $item): string => __('Review of :item featured.', ['item' => $item->name]));
    }

    /**
     * Stop featuring a review.
     *
     * @throws ValidationException for a review that is not featured
     */
    public function unfeature(Review $review): Review
    {
        return $this->moderate($review, function (Review $locked): void {
            if (! $locked->is_featured) {
                throw ValidationException::withMessages(['review' => __('This review is not featured.')]);
            }

            $locked->forceFill(['is_featured' => false])->save();
        }, AuditAction::ReviewUnfeatured, fn (Item $item): string => __('Review of :item no longer featured.', ['item' => $item->name]));
    }

    /**
     * Delete a review. Its booking stays reviewed: the customer cannot write
     * another.
     */
    public function delete(Review $review): void
    {
        $this->moderate($review, function (Review $locked): void {
            $locked->delete();
        }, AuditAction::ReviewDeleted, fn (Item $item): string => __('Review of :item deleted.', ['item' => $item->name]));
    }

    /**
     * Lock the review's item, then the review (always in that order), apply
     * the change, recompute the rating and record it.
     *
     * @param  callable(Review): void  $change
     * @param  Closure(Item): string  $describe
     */
    private function moderate(Review $review, callable $change, AuditAction $action, Closure $describe): Review
    {
        return DB::transaction(function () use ($review, $change, $action, $describe): Review {
            $item = $this->lockItem($review->item_id);
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            $change($locked);
            $item->recomputeRating();

            $this->recordAuditEvent($action, $locked, $describe($item), context: [
                'item_id' => $item->id,
                'rating_avg' => $item->rating_avg,
                'reviews_count' => $item->reviews_count,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureReviewable(Booking $booking, Item $item, User $customer): void
    {
        if ($booking->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['booking' => __('That booking is not yours.')]);
        }

        if (! $booking->fulfilment_status->allowsReview()) {
            throw ValidationException::withMessages(['booking' => __(':reference can be reviewed once it is completed.', ['reference' => $booking->reference])]);
        }

        if (! $booking->isPaidInFull()) {
            throw ValidationException::withMessages(['booking' => __(':reference can be reviewed once it is paid in full.', ['reference' => $booking->reference])]);
        }

        if (! $booking->lines()->where('item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['item' => __(':item is not on :reference.', ['item' => $item->name, 'reference' => $booking->reference])]);
        }

        // A deleted review still holds the booking's place in the unique key.
        if (Review::withTrashed()->whereBelongsTo($booking)->exists()) {
            throw ValidationException::withMessages(['booking' => __(':reference is already reviewed.', ['reference' => $booking->reference])]);
        }
    }

    /**
     * Lock the item's row, deleted or not, so its rating is written by one
     * transaction at a time.
     */
    private function lockItem(int $itemId): Item
    {
        return Item::withTrashed()->whereKey($itemId)->lockForUpdate()->firstOrFail();
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
