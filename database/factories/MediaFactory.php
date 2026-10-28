<?php

namespace Database\Factories;

use App\Enums\MediaType;
use App\Models\Item;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows only: no file is written to the disk.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * A gallery image, not featured.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'disk' => Media::DISK,
            'path' => fn (array $attributes): string => 'items/'.fake()->uuid().'.jpg',
            'file_name' => 'photo.jpg',
            'media_type' => MediaType::Image,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120000,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the image is its item's featured image.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['featured_for_item_id' => $attributes['item_id']]);
    }

    /**
     * Indicate that the file is a PDF, which cannot be featured.
     */
    public function document(): static
    {
        return $this->state(fn () => ['media_type' => MediaType::Document, 'mime_type' => 'application/pdf', 'path' => 'items/brochure.pdf', 'file_name' => 'brochure.pdf']);
    }
}
