<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Model events must stay enabled here: tenant database provisioning is triggered by
 * the TenantCreated event, so WithoutModelEvents would leave tenants without a database.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            UserSeeder::class,
            SubscriptionSeeder::class,
            TenantSettingsSeeder::class,
            CatalogSeeder::class,
            BookingSeeder::class,
            WishlistSeeder::class,
        ]);
    }
}
