<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSettingsSeeder extends Seeder
{
    /**
     * Seed the one settings row every tenant database needs. Run TenantSeeder
     * first.
     *
     * A freshly provisioned tenant has an empty `settings` table today because
     * `Jobs\SeedDatabase` is disabled in TenancyServiceProvider, so this is run
     * by hand from the root DatabaseSeeder instead. Skips tenants that already
     * have a row, since `singleton` is unique and a second insert would fail.
     */
    public function run(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->run(function () use ($tenant): void {
                if (DB::table('settings')->where('singleton', 1)->exists()) {
                    return;
                }

                DB::table('settings')->insert([
                    'singleton' => 1,
                    'timezone' => 'Asia/Manila',
                    'locale' => 'en',
                    'booking_hold_minutes' => $tenant->id === 'dumaguete' ? 720 : 1440,
                    'deposit_percentage' => $tenant->id === 'dumaguete' ? 40 : 50,
                    'cancellation_window_hours' => 48,
                    'default_check_in_time' => '14:00:00',
                    'default_check_out_time' => '12:00:00',
                    'requires_listing_approval' => true,
                    'created_on' => now(),
                    'updated_on' => now(),
                ]);
            });
        });
    }
}
