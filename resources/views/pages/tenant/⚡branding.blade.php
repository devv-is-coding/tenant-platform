<?php

use App\Actions\Agency\UpdateAgencyBrandingAction;
use App\Enums\PlanFeature;
use App\Models\Settings;
use App\Models\Tenant;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
| How the agency's own app looks: its name, colours, logo and support
| contacts. The app reads them at runtime (GET /api/v1/app-config, CU-2), so
| a change shows without a new app release. Its Admin's alone.
|
| Saving needs BrandedApp. An agency without it, or a read-only one, still
| sees what it saved, which is kept; the app shows the platform's defaults
| in its place until the agency has the feature again.
|
| Saving re-checks the policy, since Livewire update requests do not re-run
| the route middleware's role checks on their own.
*/
new #[Title('App branding')] class extends Component {
    use WithFileUploads;

    public string $app_display_name = '';

    public string $brand_primary_color = '';

    public string $brand_accent_color = '';

    public string $support_email = '';

    public string $support_phone = '';

    public ?TemporaryUploadedFile $logo = null;

    public bool $remove_logo = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Settings::class);

        $this->fillFrom(Settings::current());
    }

    /**
     * Save the agency's branding.
     */
    public function save(UpdateAgencyBrandingAction $updateBranding): void
    {
        $settings = Settings::current();
        $this->authorize('updateBranding', $settings);

        $changes = $updateBranding->handle($settings, $this->only(array_keys($updateBranding->rules())));

        $this->reset('logo', 'remove_logo');
        $this->fillFrom($settings);
        unset($this->saved);
        Flux::toast(variant: 'success', text: $changes === [] ? __('Nothing to save: the branding is unchanged.') : __('Branding saved. The app shows it the next time it opens.'));
    }

    /**
     * Get the agency the branding is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }

    /**
     * Get the branding as saved, for the preview.
     */
    #[Computed]
    public function saved(): Settings
    {
        $this->authorize('viewAny', Settings::class);

        return Settings::current();
    }

    /**
     * Determine if the signed-in user may save the form now.
     */
    #[Computed]
    public function canSave(): bool
    {
        return auth()->user()->can('updateBranding', $this->saved);
    }

    /**
     * Show the saved values in the form, empty where the platform's default
     * applies.
     */
    private function fillFrom(Settings $settings): void
    {
        $this->app_display_name = (string) $settings->app_display_name;
        $this->brand_primary_color = (string) $settings->brand_primary_color;
        $this->brand_accent_color = (string) $settings->brand_accent_color;
        $this->support_email = (string) $settings->support_email;
        $this->support_phone = (string) $settings->support_phone;
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('App branding') }}</flux:heading>
        <flux:text class="mt-1">{{ __('How :tenant\'s app looks to your customers. Changes reach the app the next time it opens, with no new release.', ['tenant' => $this->agency->name ?? $this->agency->id]) }}</flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    @if (! $this->agency->status->isReadOnly())
        @unlessfeature(PlanFeature::BrandedApp)
            <x-tenant.feature-locked :feature="PlanFeature::BrandedApp" />
        @endfeature
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="flex flex-col gap-6 lg:col-span-2">
            <fieldset class="contents" @disabled(! $this->canSave)>
                <flux:card class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Look') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Leave a field empty to use the platform\'s default.') }}</flux:text>
                    </div>

                    <flux:input wire:model="app_display_name" :label="__('App name')" :description="__('Shown under the icon and in the app\'s header, up to 40 characters.')" maxlength="40" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['brand_primary_color' => __('Primary colour'), 'brand_accent_color' => __('Accent colour')] as $field => $label)
                            <flux:field wire:key="{{ $field }}">
                                <flux:label>{{ $label }}</flux:label>

                                <flux:input.group>
                                    <flux:input.group.prefix>
                                        <span
                                            class="size-4 rounded-sm border border-zinc-300 dark:border-zinc-600"
                                            @if (preg_match('/^#[0-9A-Fa-f]{6}$/', $this->{$field})) style="background-color: {{ $this->{$field} }}" @endif
                                        ></span>
                                    </flux:input.group.prefix>

                                    <flux:input wire:model.live.debounce.300ms="{{ $field }}" placeholder="#0E7490" maxlength="7" />
                                </flux:input.group>

                                <flux:error :name="$field" />
                            </flux:field>
                        @endforeach
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Logo') }}</flux:label>
                        <flux:description>{{ __('PNG, JPEG or WebP, up to 1 MB. A square image works best.') }}</flux:description>

                        <flux:input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" />

                        <flux:error name="logo" />
                    </flux:field>

                    @if ($this->saved->logo_path && ! $logo)
                        <flux:checkbox wire:model="remove_logo" :label="__('Remove the current logo')" />
                    @endif
                </flux:card>

                <flux:card class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Support') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Where customers reach you from the app\'s help screen.') }}</flux:text>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="support_email" type="email" :label="__('Email')" :placeholder="__('Optional')" />
                        <flux:input wire:model="support_phone" type="tel" :label="__('Phone')" :placeholder="__('Optional')" maxlength="20" />
                    </div>
                </flux:card>
            </fieldset>

            @if ($this->canSave)
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" data-test="save-branding-button">{{ __('Save branding') }}</flux:button>
                </div>
            @endif
        </form>

        {{-- Saved values only: what the app shows now. --}}
        <aside class="flex flex-col gap-4">
            <flux:card class="flex flex-col gap-4" data-test="branding-preview">
                <flux:heading size="lg" level="2">{{ __('As saved') }}</flux:heading>

                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div
                        class="flex items-center gap-3 bg-zinc-800 p-4 text-white"
                        @if ($this->saved->brand_primary_color) style="background-color: {{ $this->saved->brand_primary_color }}" @endif
                    >
                        @if ($this->saved->logo_path)
                            <img src="{{ tenant_asset($this->saved->logo_path) }}" alt="{{ __('Logo') }}" class="size-10 rounded-md bg-white object-contain" />
                        @else
                            <div class="flex size-10 items-center justify-center rounded-md bg-white/20">
                                <flux:icon.photo variant="mini" />
                            </div>
                        @endif

                        <span class="font-medium">{{ $this->saved->app_display_name ?? $this->agency->name ?? $this->agency->id }}</span>
                    </div>

                    <div class="flex flex-col gap-3 p-4">
                        <flux:text size="sm">{{ __('Buttons and highlights use the accent colour.') }}</flux:text>
                        <span
                            class="inline-flex w-fit rounded-md bg-zinc-500 px-3 py-1.5 text-sm font-medium text-white"
                            @if ($this->saved->brand_accent_color) style="background-color: {{ $this->saved->brand_accent_color }}" @endif
                        >{{ __('Book now') }}</span>
                    </div>
                </div>

                @if ($this->saved->support_email || $this->saved->support_phone)
                    <div>
                        <flux:text size="sm" class="mb-1">{{ __('Support') }}</flux:text>
                        <flux:text class="text-zinc-800 dark:text-white">{{ collect([$this->saved->support_email, $this->saved->support_phone])->filter()->implode(' · ') }}</flux:text>
                    </div>
                @endif
            </flux:card>
        </aside>
    </div>
</div>
