<?php

namespace App\Actions\Agency;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Models\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UpdateAgencyBrandingAction
{
    use RecordsAuditEvents;

    /**
     * The disk logos are stored on. Tenancy roots it in the agency's own
     * storage folder, so one agency's logo can never be read or replaced
     * through another's domain.
     */
    public const LOGO_DISK = 'public';

    /**
     * Change how the agency's app looks, recording what changed from what in
     * a `settings.updated` event. Saving nothing new records nothing.
     *
     * A new logo replaces the old one, whose file is removed so replaced
     * logos do not pile up in the agency's storage. Empty fields are stored
     * as NULL, which the app reads as the platform's default.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> what changed
     */
    public function handle(Settings $settings, array $input): array
    {
        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();

        $settings->fill([
            'app_display_name' => $this->nullIfBlank($validated['app_display_name'] ?? null),
            'brand_primary_color' => $this->color($validated['brand_primary_color'] ?? null),
            'brand_accent_color' => $this->color($validated['brand_accent_color'] ?? null),
            'support_email' => $this->nullIfBlank($validated['support_email'] ?? null),
            'support_phone' => $this->nullIfBlank($validated['support_phone'] ?? null),
        ]);

        $previousLogo = $settings->logo_path;
        $logo = $validated['logo'] ?? null;

        if ($logo instanceof UploadedFile) {
            $settings->logo_path = $logo->store('branding', self::LOGO_DISK) ?: null;
        } elseif (! empty($validated['remove_logo'])) {
            $settings->logo_path = null;
        }

        $changes = [];

        foreach (array_keys($settings->getDirty()) as $attribute) {
            $changes[$attribute] = ['old' => $settings->getOriginal($attribute), 'new' => $settings->getAttribute($attribute)];
        }

        if ($changes === []) {
            return [];
        }

        $settings->getConnection()->transaction(function () use ($settings, $changes): void {
            $settings->save();

            $this->recordAuditEvent(AuditAction::SettingsUpdated, $settings, __('App branding updated.'), $changes);
        });

        if ($previousLogo !== null && $previousLogo !== $settings->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($previousLogo);
        }

        return $changes;
    }

    /**
     * Get the rules for the agency's branding. Public so the branding page
     * can share them.
     *
     * The logo is a raster image only: an SVG can carry script, and it is
     * served from the agency's own domain.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'app_display_name' => ['nullable', 'string', 'max:40'],
            'brand_primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'string', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['boolean'],
        ];
    }

    /**
     * Get the messages for the branding rules.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'brand_primary_color.regex' => __('Enter the colour as a hex code, such as #0E7490.'),
            'brand_accent_color.regex' => __('Enter the colour as a hex code, such as #F59E0B.'),
        ];
    }

    /**
     * Store a colour in one form, upper case, so #0e7490 and #0E7490 are one
     * value and not a change.
     */
    private function color(mixed $value): ?string
    {
        $color = $this->nullIfBlank($value);

        return $color === null ? null : strtoupper($color);
    }

    /**
     * Treat an empty or whitespace-only field as not set.
     */
    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
