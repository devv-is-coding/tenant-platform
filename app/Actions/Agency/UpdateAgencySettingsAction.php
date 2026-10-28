<?php

namespace App\Actions\Agency;

use App\Concerns\RecordsAuditEvents;
use App\Enums\AuditAction;
use App\Models\Settings;
use Illuminate\Support\Facades\Validator;

class UpdateAgencySettingsAction
{
    use RecordsAuditEvents;

    /**
     * Change the agency's operating defaults, recording what changed from
     * what in a `settings.updated` event. Saving nothing new records nothing.
     *
     * Times arrive as `H:i`, as a time input sends them, and are stored as
     * the column's `H:i:s`, so an unchanged time does not read as a change.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> what changed
     */
    public function handle(Settings $settings, array $input): array
    {
        $validated = Validator::make($input, $this->rules())->validate();

        $settings->fill([
            'deposit_percentage' => (int) $validated['deposit_percentage'],
            'booking_hold_minutes' => (int) $validated['booking_hold_minutes'],
            'cancellation_window_hours' => (int) $validated['cancellation_window_hours'],
            'default_check_in_time' => $validated['default_check_in_time'].':00',
            'default_check_out_time' => $validated['default_check_out_time'].':00',
            'requires_listing_approval' => (bool) $validated['requires_listing_approval'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
        ]);

        $changes = [];

        foreach (array_keys($settings->getDirty()) as $attribute) {
            $changes[$attribute] = ['old' => $settings->getOriginal($attribute), 'new' => $settings->getAttribute($attribute)];
        }

        if ($changes === []) {
            return [];
        }

        $settings->getConnection()->transaction(function () use ($settings, $changes): void {
            $settings->save();

            $this->recordAuditEvent(AuditAction::SettingsUpdated, $settings, __('Operating defaults updated.'), $changes);
        });

        return $changes;
    }

    /**
     * Get the rules for the agency's operating defaults. Public so the
     * settings page can share them.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'deposit_percentage' => ['required', 'integer', 'between:0,100'],
            // At least a quarter of an hour, at most a week.
            'booking_hold_minutes' => ['required', 'integer', 'between:15,10080'],
            // Up to 30 days before departure.
            'cancellation_window_hours' => ['required', 'integer', 'between:0,720'],
            'default_check_in_time' => ['required', 'date_format:H:i'],
            'default_check_out_time' => ['required', 'date_format:H:i'],
            'requires_listing_approval' => ['required', 'boolean'],
            'timezone' => ['required', 'string', 'timezone:all'],
            // A language code, optionally with a region: en, fil, en_PH.
            'locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ];
    }
}
