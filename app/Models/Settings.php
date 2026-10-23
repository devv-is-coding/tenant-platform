<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An agency's operating defaults: the one row in its own `settings` table,
 * created when the agency's database is provisioned. Read it with
 * `Settings::current()`.
 *
 * No CentralConnection: the model uses whichever tenant database tenancy has
 * made the default, so it must only be touched inside tenant context. The
 * agency's currency is not here; it lives only in `tenants.data`, read as
 * `tenant('currency')`.
 *
 * @property int $id
 * @property string $timezone
 * @property string $locale
 * @property int $booking_hold_minutes
 * @property int $deposit_percentage
 * @property int $cancellation_window_hours
 * @property string $default_check_in_time
 * @property string $default_check_out_time
 * @property bool $requires_listing_approval
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'timezone',
    'locale',
    'booking_hold_minutes',
    'deposit_percentage',
    'cancellation_window_hours',
    'default_check_in_time',
    'default_check_out_time',
    'requires_listing_approval',
])]
class Settings extends Model
{
    use SoftDeletes;

    /**
     * Get the current agency's settings row.
     */
    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_hold_minutes' => 'integer',
            'deposit_percentage' => 'integer',
            'cancellation_window_hours' => 'integer',
            'requires_listing_approval' => 'boolean',
        ];
    }
}
