<?php

use App\Actions\Catalog\StoreItemMediaAction;
use App\Enums\AuditAction;
use App\Enums\MediaType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Item;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

/**
 * Upload the file to a new item of the agency, as a new member of its staff.
 *
 * @return array{media: Media, item: Item, uploader: User}
 */
function uploadToNewItem(Tenant $tenant, UploadedFile $file): array
{
    $uploader = User::factory()->role(Role::Staff)->for($tenant)->create();
    actingAs($uploader);

    return withinTenant($tenant, function () use ($file, $uploader): array {
        $item = Item::factory()->create();

        return ['media' => app(StoreItemMediaAction::class)->handle($item, ['file' => $file]), 'item' => $item, 'uploader' => $uploader];
    });
}

/**
 * A real uploaded file with the given contents and name, so its type is read
 * from the contents as on a real upload (a fake one reports its name's).
 */
function uploadedFileWithContent(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

test('an upload lands on the agency\'s own disk under the item, and is recorded by who uploaded it', function () {
    $tenant = tenantOnDomain();

    ['media' => $media, 'item' => $item, 'uploader' => $uploader] = uploadToNewItem($tenant, UploadedFile::fake()->image('tokyo.jpg', 800, 600));

    $onDisk = withinTenant($tenant, fn (): array => [
        'exists' => Storage::disk(Media::DISK)->exists($media->path),
        'path' => Storage::disk(Media::DISK)->path($media->path),
        'event' => AuditEvent::sole(),
    ]);

    expect($media->disk)->toBe(Media::DISK)
        ->and($media->path)->toStartWith("items/{$item->id}/")
        ->and($media->file_name)->toBe('tokyo.jpg')
        ->and($media->media_type)->toBe(MediaType::Image)
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($onDisk['exists'])->toBeTrue()
        ->and($onDisk['path'])->toContain("tenant{$tenant->id}")
        ->and($onDisk['event']->action)->toBe(AuditAction::MediaUploaded)
        ->and($onDisk['event']->actor_id)->toBe($uploader->id)
        ->and($onDisk['event']->subject_type)->toBe('media');
});

test('another agency\'s disk never has the file', function () {
    $tenant = tenantOnDomain();
    $other = tenantOnDomain();

    ['media' => $media] = uploadToNewItem($tenant, UploadedFile::fake()->image('tokyo.jpg'));

    expect(withinTenant($other, fn (): bool => Storage::disk(Media::DISK)->exists($media->path)))->toBeFalse();
});

test('the type is read from the file, and each upload goes to the end of the gallery', function () {
    $tenant = tenantOnDomain();
    actingAs(User::factory()->role(Role::Staff)->for($tenant)->create());

    $uploaded = withinTenant($tenant, function (): array {
        $item = Item::factory()->create();
        $store = app(StoreItemMediaAction::class);

        return array_map(fn (UploadedFile $file): array => $store->handle($item, ['file' => $file])->only(['media_type', 'sort_order']), [
            UploadedFile::fake()->image('room.png'),
            UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->create('walkthrough.mp4', 2000, 'video/mp4'),
        ]);
    });

    expect($uploaded)->toBe([
        ['media_type' => MediaType::Image, 'sort_order' => 1],
        ['media_type' => MediaType::Document, 'sort_order' => 2],
        ['media_type' => MediaType::Video, 'sort_order' => 3],
    ]);
});

test('a file that is not an allowed type, or too large for its type, is refused and nothing is stored', function (Closure $makeFile, string $message) {
    $tenant = tenantOnDomain();
    actingAs(User::factory()->role(Role::Staff)->for($tenant)->create());

    withinTenant($tenant, function () use ($makeFile, $message): void {
        try {
            app(StoreItemMediaAction::class)->handle(Item::factory()->create(), ['file' => $makeFile()]);
            $this->fail('The upload was accepted.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['file'])->toBe([$message]);
        }

        expect(Media::count())->toBe(0)
            ->and(Storage::disk(Media::DISK)->allFiles())->toBe([]);
    });
})->with([
    'an SVG, which can carry script' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Upload a PNG, JPEG or WebP image, an MP4 video or a PDF.'],
    'a text file named as an image' => [fn () => uploadedFileWithContent('photo.jpg', 'not really a photo'), 'Upload a PNG, JPEG or WebP image, an MP4 video or a PDF.'],
    'an image over 5 MB' => [fn () => UploadedFile::fake()->image('huge.jpg')->size(5 * 1024 + 1), 'The file may be at most 5 MB.'],
    'a PDF over 10 MB' => [fn () => UploadedFile::fake()->create('huge.pdf', 10 * 1024 + 1, 'application/pdf'), 'The file may be at most 10 MB.'],
    'no file' => [fn () => null, 'Choose a file to upload.'],
]);
