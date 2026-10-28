<?php

use App\Models\Item;
use App\Models\Media;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\get;

test('the URL is generated from the disk and path, on the agency\'s own domain, and serves the file', function () {
    $tenant = tenantOnDomain();
    // As while serving one of the agency's own requests, a page or the API.
    URL::forceRootUrl("http://{$tenant->id}.localhost");

    $url = withinTenant($tenant, function (): string {
        Storage::disk(Media::DISK)->put('items/7/tokyo.jpg', 'jpeg bytes');

        return Media::factory()->create(['path' => 'items/7/tokyo.jpg'])->url();
    });

    expect($url)->toBe("http://{$tenant->id}.localhost/tenancy/assets/items/7/tokyo.jpg");

    get($url)->assertOk();
});

test('a file on another disk gets that disk\'s own URL', function () {
    Storage::fake('s3', ['url' => 'https://cdn.example.test']);
    $tenant = tenantOnDomain();

    $url = withinTenant($tenant, fn () => Media::factory()->create(['disk' => 's3', 'path' => 'items/7/tokyo.jpg'])->url());

    expect($url)->toBe('https://cdn.example.test/items/7/tokyo.jpg');
});

test('an item has at most one featured image, held by the schema itself', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $item = Item::factory()->create();
        Media::factory()->for($item)->featured()->create();

        expect(fn () => Media::factory()->for($item)->featured()->create())->toThrow(UniqueConstraintViolationException::class);

        Media::factory()->for($item)->count(2)->create();

        expect($item->media()->count())->toBe(3);
    });
});

test('featuring an image moves the feature from the one before it', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $item = Item::factory()->create();
        $old = Media::factory()->for($item)->featured()->create();
        $new = Media::factory()->for($item)->create();

        $new->markAsFeatured();

        expect($new->fresh()->is_featured)->toBeTrue()
            ->and($old->fresh()->is_featured)->toBeFalse()
            ->and($item->featuredMedia()->value('id'))->toBe($new->id);
    });
});

test('only an image can be featured', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $document = Media::factory()->document()->create();

        expect(fn () => $document->markAsFeatured())->toThrow(LogicException::class, 'A Document cannot be an item\'s featured image; only an image can.')
            ->and($document->fresh()->is_featured)->toBeFalse();
    });
});

test('deleting the featured image gives up the slot, so another can take it', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $item = Item::factory()->create();
        $featured = Media::factory()->for($item)->featured()->create();

        $featured->delete();

        expect(Media::withTrashed()->findOrFail($featured->id)->featured_for_item_id)->toBeNull();

        Media::factory()->for($item)->create()->markAsFeatured();

        expect($item->featuredMedia()->exists())->toBeTrue();
    });
});
