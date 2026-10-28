<?php

namespace App\Actions\Catalog;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Enums\MediaType;
use App\Models\Item;
use App\Models\Media;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreItemMediaAction
{
    use RecordsAuditEvents;

    /**
     * Add an uploaded file to the item's gallery, on the agency's own disk
     * under `items/{item id}/`, at the end of the gallery's order.
     *
     * The type is read from the file's own contents, not its name. Featuring
     * it is a separate step (Media::markAsFeatured()), and only images can be.
     *
     * @param  array<string, mixed>  $input  with `file`, an UploadedFile
     */
    public function handle(Item $item, array $input): Media
    {
        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();

        $file = $validated['file'];
        $path = $file instanceof UploadedFile ? $file->store("items/{$item->id}", Media::DISK) : false;

        if (! $file instanceof UploadedFile || $path === false) {
            throw new RuntimeException("The upload for item [{$item->id}] could not be stored.");
        }

        $mimeType = (string) $file->getMimeType();

        try {
            $media = Media::create([
                'item_id' => $item->id,
                'disk' => Media::DISK,
                'path' => $path,
                'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'media_type' => $this->typeOf($mimeType),
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'sort_order' => (int) Media::query()->where('item_id', $item->id)->max('sort_order') + 1,
            ]);
        } catch (Throwable $exception) {
            Storage::disk(Media::DISK)->delete($path);

            throw $exception;
        }

        $this->recordAuditEvent(AuditAction::MediaUploaded, $media, __(':file added to :item.', ['file' => $media->file_name, 'item' => $item->name]));

        return $media;
    }

    /**
     * Get the rules for an uploaded gallery file: an image (PNG, JPEG or
     * WebP) up to 5 MB, an MP4 video up to 50 MB, or a PDF up to 10 MB.
     *
     * No SVG: it can carry script, and gallery files are served from the
     * agency's own domain.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,video/mp4,application/pdf',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $limitKilobytes = match ($this->typeOf((string) $value->getMimeType())) {
                        MediaType::Image => 5 * 1024,
                        MediaType::Document => 10 * 1024,
                        MediaType::Video => 50 * 1024,
                    };

                    if ($value->getSize() > $limitKilobytes * 1024) {
                        $fail(__('The file may be at most :size MB.', ['size' => $limitKilobytes / 1024]));
                    }
                },
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'file.required' => __('Choose a file to upload.'),
            'file.mimetypes' => __('Upload a PNG, JPEG or WebP image, an MP4 video or a PDF.'),
        ];
    }

    /**
     * Read the media type from a file's MIME type.
     */
    private function typeOf(string $mimeType): MediaType
    {
        return match (true) {
            str_starts_with($mimeType, 'video/') => MediaType::Video,
            $mimeType === 'application/pdf' => MediaType::Document,
            default => MediaType::Image,
        };
    }
}
