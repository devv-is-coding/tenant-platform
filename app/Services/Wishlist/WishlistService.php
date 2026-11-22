<?php

namespace App\Services\Wishlist;

use App\Enums\ItemStatus;
use App\Models\Item;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The items a customer saves in their agency's app, ported from the legacy
 * `likes` (P19). One row per customer and item (UNIQUE(customer_id,
 * item_id)): removing soft-deletes it, and saving again restores that row,
 * since the deleted row still holds the key.
 *
 * Only an item on sale can be saved, and the wishlist shows only items still
 * on sale: one since unpublished or deleted drops out, and comes back if it
 * returns. Deleting an item for good takes its rows with it (the foreign key
 * cascades).
 */
class WishlistService
{
    /**
     * Save the item to the customer's wishlist. Saving it twice is harmless.
     *
     * @throws ValidationException for an item not on sale
     */
    public function save(User $customer, Item $item): WishlistItem
    {
        if ($item->trashed() || ! $item->item_status->isVisibleToCustomers()) {
            throw ValidationException::withMessages(['item' => __(':item is not available.', ['item' => $item->name])]);
        }

        $saved = WishlistItem::withTrashed()->createOrFirst(['customer_id' => $customer->id, 'item_id' => $item->id]);

        if ($saved->trashed()) {
            $saved->restore();
        }

        return $saved;
    }

    /**
     * Take the item off the customer's wishlist, if it is there.
     */
    public function remove(User $customer, Item $item): void
    {
        WishlistItem::query()->where('customer_id', $customer->id)->whereBelongsTo($item)->delete();
    }

    /**
     * Get what the customer has saved that is still on sale, with each item,
     * most recently saved first.
     *
     * @return Collection<int, WishlistItem>
     */
    public function savedBy(User $customer): Collection
    {
        return WishlistItem::query()
            ->where('customer_id', $customer->id)
            ->whereHas('item', fn (Builder $query) => $query->where('item_status', ItemStatus::Published))
            ->with('item')
            ->latest('updated_at')
            ->latest('id')
            ->get();
    }
}
