<?php

namespace App\Models;

use App\Casts\StringList;
use App\Concerns\BelongsToItemOfType;
use App\Enums\ItemType;
use Database\Factories\HotelProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A hotel's typed attributes, replacing the legacy EAV rows (defect 6).
 * Check-in and check-out are real `time` columns, held as "H:i:s" strings,
 * where the legacy stored the text "2:00 PM".
 *
 * @property int $id
 * @property int $item_id
 * @property int $max_adults
 * @property list<string> $amenities
 * @property string $check_in_time
 * @property string $check_out_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'max_adults',
    'amenities',
    'check_in_time',
    'check_out_time',
])]
class HotelProfile extends Model
{
    /** @use HasFactory<HotelProfileFactory> */
    use BelongsToItemOfType, HasFactory, SoftDeletes;

    /**
     * Get the type of item a hotel profile belongs to.
     */
    public static function itemType(): ItemType
    {
        return ItemType::Hotel;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_adults' => 'integer',
            'amenities' => StringList::class,
        ];
    }
}
