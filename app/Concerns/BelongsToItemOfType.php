<?php

namespace App\Concerns;

use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For a row that only one type of item may have: a tour, visa or hotel
 * profile, or an itinerary stop (tours only).
 *
 * The schema cannot say this: `item_id` is unique on each profile table, which
 * stops two tour profiles on one item but not a tour profile on a hotel. So
 * the model refuses it on every save.
 *
 * @mixin Model
 */
trait BelongsToItemOfType
{
    /**
     * Get the type of item this row belongs to.
     */
    abstract public static function itemType(): ItemType;

    /**
     * Refuse to save the row against an item of another type.
     */
    public static function bootBelongsToItemOfType(): void
    {
        static::saving(function (Model $model): void {
            $item = Item::withTrashed()->find($model->getAttribute('item_id'));

            if ($item instanceof Item && $item->item_type !== static::itemType()) {
                throw new LogicException(sprintf(
                    'A %s item cannot have a %s; only a %s item can.',
                    $item->item_type->label(),
                    class_basename($model),
                    static::itemType()->label(),
                ));
            }
        });
    }

    /**
     * Get the item the row belongs to.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
