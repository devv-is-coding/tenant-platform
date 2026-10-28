<?php

use App\Actions\Agency\UpdateAgencySettingsAction;
use App\Models\Settings;
use App\Models\Tenant;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The agency's operating defaults: deposit, hold and cancellation terms,
| hotel times, listing approval, timezone and locale. Its Admin's alone.
| Every tier sets its own, so nothing here is plan-gated; while the agency is
| read-only the form shows the current values but cannot be saved.
|
| Saving re-checks the policy, since Livewire update requests do not re-run
| the route middleware's role checks on their own.
*/
new #[Title('Agency settings')] class extends Component {
    public string $deposit_percentage = '';

    public string $booking_hold_minutes = '';

    public string $cancellation_window_hours = '';

    public string $default_check_in_time = '';

    public string $default_check_out_time = '';

    public bool $requires_listing_approval = true;

    public string $timezone = '';

    public string $locale = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Settings::class);

        $this->fillFrom(Settings::current());
    }

    /**
     * Save the agency's operating defaults.
     */
    public function save(UpdateAgencySettingsAction $updateSettings): void
    {
        $settings = Settings::current();
        $this->authorize('update', $settings);

        $changes = $updateSettings->handle($settings, $this->only(array_keys($updateSettings->rules())));

        $this->fillFrom($settings);
        Flux::toast(variant: 'success', text: $changes === [] ? __('Nothing to save: the settings are unchanged.') : __('Settings saved.'));
    }

    /**
     * Get the agency the settings are for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }

    /**
     * Determine if the signed-in user may save the form now.
     */
    #[Computed]
    public function canSave(): bool
    {
        return auth()->user()->can('update', Settings::current());
    }

    /**
     * Get every timezone the agency can operate in.
     *
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * Show the stored values in the form, times as the time inputs take them.
     */
    private function fillFrom(Settings $settings): void
    {
        $this->deposit_percentage = (string) $settings->deposit_percentage;
        $this->booking_hold_minutes = (string) $settings->booking_hold_minutes;
        $this->cancellation_window_hours = (string) $settings->cancellation_window_hours;
        $this->default_check_in_time = substr($settings->default_check_in_time, 0, 5);
        $this->default_check_out_time = substr($settings->default_check_out_time, 0, 5);
        $this->requires_listing_approval = $settings->requires_listing_approval;
        $this->timezone = $settings->timezone;
        $this->locale = $settings->locale;
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Agency settings') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The defaults :tenant operates on. New bookings and listings take them; changing one does not alter what was already sold.', ['tenant' => $this->agency->name ?? $this->agency->id]) }}</flux:text>
    </div>

    <x-tenant.read-only-notice :agency="$this->agency" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <fieldset class="contents" @disabled(! $this->canSave)>
            <div class="grid gap-6 lg:grid-cols-2">
                <flux:card class="flex flex-col gap-4" data-test="booking-terms">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Booking terms') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('What a customer pays up front, and how long they have.') }}</flux:text>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Deposit') }}</flux:label>
                        <flux:description>{{ __('The share of the total taken as a deposit, from 0 to 100.') }}</flux:description>

                        <flux:input.group>
                            <flux:input wire:model="deposit_percentage" type="number" min="0" max="100" required />
                            <flux:input.group.suffix>%</flux:input.group.suffix>
                        </flux:input.group>

                        <flux:error name="deposit_percentage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Booking hold') }}</flux:label>
                        <flux:description>{{ __('How long an unpaid booking holds its places before they are released, from 15 minutes to a week.') }}</flux:description>

                        <flux:input.group>
                            <flux:input wire:model="booking_hold_minutes" type="number" min="15" max="10080" required />
                            <flux:input.group.suffix>{{ __('minutes') }}</flux:input.group.suffix>
                        </flux:input.group>

                        <flux:error name="booking_hold_minutes" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Cancellation window') }}</flux:label>
                        <flux:description>{{ __('How close to departure a customer may still cancel, up to 30 days.') }}</flux:description>

                        <flux:input.group>
                            <flux:input wire:model="cancellation_window_hours" type="number" min="0" max="720" required />
                            <flux:input.group.suffix>{{ __('hours') }}</flux:input.group.suffix>
                        </flux:input.group>

                        <flux:error name="cancellation_window_hours" />
                    </flux:field>
                </flux:card>

                <div class="flex flex-col gap-6">
                    <flux:card class="flex flex-col gap-4">
                        <div>
                            <flux:heading size="lg" level="2">{{ __('Hotel stays') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('The times a new hotel listing starts with.') }}</flux:text>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="default_check_in_time" type="time" :label="__('Check-in')" required />
                            <flux:input wire:model="default_check_out_time" type="time" :label="__('Check-out')" required />
                        </div>
                    </flux:card>

                    <flux:card class="flex flex-col gap-4">
                        <div>
                            <flux:heading size="lg" level="2">{{ __('Catalog') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Who has the last word on what customers see.') }}</flux:text>
                        </div>

                        <flux:checkbox
                            wire:model="requires_listing_approval"
                            :label="__('Listings need a manager\'s approval')"
                            :description="__('When off, a listing staff create is published without one.')"
                        />
                    </flux:card>

                    <flux:card class="flex flex-col gap-4">
                        <div>
                            <flux:heading size="lg" level="2">{{ __('Region') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('The clock and language the agency works in.') }}</flux:text>
                        </div>

                        <flux:select wire:model="timezone" :label="__('Timezone')" required>
                            @foreach ($this->timezones as $zone)
                                <flux:select.option :value="$zone" wire:key="timezone-{{ $zone }}">{{ $zone }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="locale" :label="__('Locale')" :description="__('A language code, optionally with a region, such as en, fil or en_PH.')" required />
                    </flux:card>
                </div>
            </div>
        </fieldset>

        @if ($this->canSave)
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" data-test="save-settings-button">{{ __('Save settings') }}</flux:button>
            </div>
        @endif
    </form>
</div>
