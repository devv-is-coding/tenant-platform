<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Seed one tenant per Negros Oriental locality. Existing tenants are skipped so
     * the seeder can be re-run without tripping over already-created databases.
     */
    public function run(): void
    {
        foreach ($this->tenants() as $id => $attributes) {
            if (Tenant::find($id) !== null) {
                continue;
            }

            $tenant = Tenant::create([
                'id' => $id,
                // Fixture agencies are already past onboarding; a real one starts Pending.
                'status' => TenantStatus::Active,
                'name' => $attributes['name'],
                'locality_type' => $attributes['locality_type'],
                'province' => 'Negros Oriental',
                'region' => 'Negros Island Region',
                'country' => 'Philippines',
                // The central marketplace renders prices on the central domain,
                // where tenancy is never initialised, so currency lives only here.
                'currency' => 'PHP',
            ]);

            $tenant->domains()->create(['domain' => $attributes['domain']]);
        }
    }

    /**
     * @return array<string, array{name: string, locality_type: string, domain: string}>
     */
    private function tenants(): array
    {
        return [
            'zamboanguita' => [
                'name' => 'Zamboanguita',
                'locality_type' => 'Municipality',
                'domain' => 'zamboanguita.localhost',
            ],
            'dumaguete' => [
                'name' => 'Dumaguete City',
                'locality_type' => 'Component City',
                'domain' => 'dumaguete.localhost',
            ],
            'tanjay' => [
                'name' => 'Tanjay City',
                'locality_type' => 'Component City',
                'domain' => 'tanjay.localhost',
            ],
        ];
    }
}
