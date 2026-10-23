<?php

namespace Database\Seeders;

use App\Concerns\RunsInTenant;
use App\Models\Settings;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSettingsSeeder extends Seeder
{
    use RunsInTenant;

    /**
     * Give the fixture agencies their demo settings. Run TenantSeeder first.
     *
     * Provisioning already inserted each tenant's one settings row with the
     * column defaults (Tenant\TenantDatabaseSeeder), so this updates that row
     * rather than inserting, which the unique `singleton` key would refuse.
     */
    public function run(): void
    {
        $this->runInEachTenant(function (Tenant $tenant): void {
            Settings::current()->update([
                'timezone' => 'Asia/Manila',
                'locale' => 'en',
                'booking_hold_minutes' => $tenant->id === 'dumaguete' ? 720 : 1440,
                'deposit_percentage' => $tenant->id === 'dumaguete' ? 40 : 50,
                'cancellation_window_hours' => 48,
                'default_check_in_time' => '14:00:00',
                'default_check_out_time' => '12:00:00',
                'requires_listing_approval' => true,
            ]);
        });
    }
}
