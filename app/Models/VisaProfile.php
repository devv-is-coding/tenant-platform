<?php

namespace App\Models;

use App\Casts\StringList;
use App\Concerns\BelongsToItemOfType;
use App\Enums\ItemType;
use Database\Factories\VisaProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A visa's typed attributes, replacing the legacy EAV rows (defect 6).
 * Processing time is two sortable integers, never prose, and the shortest
 * can never exceed the longest.
 *
 * @property int $id
 * @property int $item_id
 * @property int $processing_days_min
 * @property int $processing_days_max
 * @property int $validity_days
 * @property list<string> $requirements
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'processing_days_min',
    'processing_days_max',
    'validity_days',
    'requirements',
])]
class VisaProfile extends Model
{
    /** @use HasFactory<VisaProfileFactory> */
    use BelongsToItemOfType, HasFactory, SoftDeletes;

    /**
     * Get the type of item a visa profile belongs to.
     */
    public static function itemType(): ItemType
    {
        return ItemType::Visa;
    }

    /**
     * Refuse an inverted processing time on every save.
     */
    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if ($profile->processing_days_min > $profile->processing_days_max) {
                throw new InvalidArgumentException("Processing cannot take at least {$profile->processing_days_min} days but at most {$profile->processing_days_max}.");
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_days_min' => 'integer',
            'processing_days_max' => 'integer',
            'validity_days' => 'integer',
            'requirements' => StringList::class,
        ];
    }
}
