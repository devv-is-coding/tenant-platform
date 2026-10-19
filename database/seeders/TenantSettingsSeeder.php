<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSettingsSeeder extends Seeder
{
    /**
     * Give the fixture agencies their demo settings. Run TenantSeeder first.
     *
     * Provisioning already inserted each tenant's one settings row with the
     * column defaults (Tenant\TenantDatabaseSeeder), so this updates that row
     * rather than inserting, which the unique `singleton` key would refuse.
     */
    public function run(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->run(function () use ($tenant): void {
                DB::table('settings')->where('singleton', 1)->update([
                    'timezone' => 'Asia/Manila',
                    'locale' => 'en',
                    'booking_hold_minutes' => $tenant->id === 'dumaguete' ? 720 : 1440,
                    'deposit_percentage' => $tenant->id === 'dumaguete' ? 40 : 50,
                    'cancellation_window_hours' => 48,
                    'default_check_in_time' => '14:00:00',
                    'default_check_out_time' => '12:00:00',
                    'requires_listing_approval' => true,
                    'updated_on' => now(),
                ]);
            });
        });
    }
}
