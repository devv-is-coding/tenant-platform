<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Seed one agency per Negros Oriental locality. Existing tenants are skipped
     * so the seeder can be re-run without tripping over already-created databases.
     *
     * Stores the same keys onboarding does: the agency's `name`, and its home
     * `locality` as a place name.
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
                'locality' => $attributes['locality'],
                'province' => 'Negros Oriental',
                'region' => 'Negros Island Region',
                'country' => 'Philippines',
                // The platform console shows prices where tenancy is never
                // initialised, so currency lives only here.
                'currency' => 'PHP',
            ]);

            $tenant->domains()->create(['domain' => $attributes['domain']]);
        }
    }

    /**
     * @return array<string, array{name: string, locality: string, domain: string}>
     */
    private function tenants(): array
    {
        return [
            'zamboanguita' => [
                'name' => 'Zamboanguita Travel & Tours',
                'locality' => 'Zamboanguita',
                'domain' => 'zamboanguita.localhost',
            ],
            'dumaguete' => [
                'name' => 'Dumaguete City Travel & Tours',
                'locality' => 'Dumaguete City',
                'domain' => 'dumaguete.localhost',
            ],
            'tanjay' => [
                'name' => 'Tanjay City Travel & Tours',
                'locality' => 'Tanjay City',
                'domain' => 'tanjay.localhost',
            ],
            'dauin' => [
                'name' => 'Dauin Travel & Tours',
                'locality' => 'Dauin',
                'domain' => 'dauin.localhost',
            ],
            'bayawan' => [
                'name' => 'Bayawan City Travel & Tours',
                'locality' => 'Bayawan City',
                'domain' => 'bayawan.localhost',
            ],
        ];
    }
}
