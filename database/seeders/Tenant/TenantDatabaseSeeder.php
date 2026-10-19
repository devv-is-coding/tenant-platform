<?php

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Seed a freshly provisioned tenant database. Runs inside tenant context,
     * from the TenantCreated pipeline's SeedDatabase job.
     *
     * Every tenant needs exactly one settings row. Only `singleton` is written,
     * so the operational defaults come from the column defaults rather than a
     * second copy here. insertOrIgnore keeps a re-run of `tenants:seed` from
     * tripping the unique `singleton` key.
     */
    public function run(): void
    {
        DB::table('settings')->insertOrIgnore([
            'singleton' => 1,
            'created_on' => now(),
            'updated_on' => now(),
        ]);
    }
}
