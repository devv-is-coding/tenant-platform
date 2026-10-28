<?php

namespace App\Models;

use App\Enums\MediaType;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * One file in an item's gallery, stored as a disk and a path, never a URL:
 * a URL in the database breaks when the storage host changes, so it is
 * generated on every read (url()).
 *
 * Gallery files go on the agency's own `public` disk, which tenancy roots in
 * its storage folder, so one agency's files are never read through another's.
 *
 * At most one image per item is featured: `featured_for_item_id` holds the
 * item's id on that one row and is unique. A deleted row gives the slot up,
 * so a replacement can take it.
 *
 * @property int $id
 * @property int $item_id
 * @property string $disk
 * @property string $path
 * @property string $file_name
 * @property MediaType $media_type
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $featured_for_item_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read bool $is_featured
 * @property-read Item|null $item
 */
#[Fillable([
    'item_id',
    'disk',
    'path',
    'file_name',
    'media_type',
    'mime_type',
    'size_bytes',
    'featured_for_item_id',
    'sort_order',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The disk gallery files are stored on.
     */
    public const DISK = 'public';

    /**
     * Free the featured slot before the row is deleted: a deleted row would
     * otherwise hold the unique key and block the replacement.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $media): void {
            if ($media->featured_for_item_id !== null && ! $media->isForceDeleting()) {
                $media->forceFill(['featured_for_item_id' => null])->saveQuietly();
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
            'media_type' => MediaType::class,
            'size_bytes' => 'integer',
            'featured_for_item_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the item the file belongs to.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Determine if this is the item's featured image.
     *
     * @return Attribute<bool, never>
     */
    protected function isFeatured(): Attribute
    {
        return Attribute::get(fn (): bool => $this->featured_for_item_id !== null);
    }

    /**
     * Get the file's URL, generated from its disk and path.
     *
     * The agency's `public` disk is rooted in the agency's storage folder,
     * which no web server path points at, so its files are served through
     * tenancy's asset route on the agency's own domain. Any other disk names
     * its own URL.
     */
    public function url(): string
    {
        return $this->disk === self::DISK && tenancy()->initialized
            ? tenant_asset($this->path)
            : Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Make this the item's featured image, in place of the one before.
     *
     * @throws LogicException when the file is not an image
     */
    public function markAsFeatured(): void
    {
        if (! $this->media_type->canBeFeatured()) {
            throw new LogicException("A {$this->media_type->label()} cannot be an item's featured image; only an image can.");
        }

        $this->getConnection()->transaction(function (): void {
            static::query()
                ->where('featured_for_item_id', $this->item_id)
                ->whereKeyNot($this->id)
                ->update(['featured_for_item_id' => null]);

            $this->update(['featured_for_item_id' => $this->item_id]);
        });
    }
}
