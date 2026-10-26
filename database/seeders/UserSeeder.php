<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed one platform-wide super admin, plus an admin, two managers, two
     * staff members and a few named customers for every tenant. Run
     * TenantSeeder first.
     *
     * Every customer belongs to exactly one agency, the one whose app they
     * signed up in; only the super admin belongs to none. The demo `customer`
     * account belongs to Dumaguete.
     *
     * Users are keyed by username so the seeder can be re-run. The role is always
     * set explicitly because the column default is super admin. Every seeded
     * account uses the factory's default password, "password".
     */
    public function run(): void
    {
        $this->seedUser('superadmin', Role::SuperAdmin);

        // Iterated on the builder: the stancl base model's TenantCollection is not
        // generic, so a fetched collection would lose the Tenant type.
        Tenant::query()->each(function (Tenant $tenant): void {
            foreach ([Role::Admin, Role::Manager, Role::Staff] as $role) {
                $this->seedUser("{$tenant->id}_".strtolower($role->name), $role, $tenant);
            }

            // A second manager and staff member, so a team has more than one
            // person per role. Catalog and booking seeders keep using the first.
            foreach ([Role::Manager, Role::Staff] as $role) {
                $this->seedUser("{$tenant->id}_".strtolower($role->name).'2', $role, $tenant);
            }

            foreach ($this->customersOf($tenant->id) as $username) {
                $this->seedCustomer($username, $tenant);
            }
        });
    }

    /**
     * Create the user unless one with the username already exists.
     */
    private function seedUser(string $username, Role $role, ?Tenant $tenant = null): void
    {
        if (User::withTrashed()->where('username', $username)->exists()) {
            return;
        }

        // Platform accounts are named after their role so they are easy to spot;
        // tenant users keep the factory's realistic names.
        $platformName = $tenant === null
            ? ['first_name' => $role->label(), 'middle_name' => null, 'last_name' => 'User']
            : [];

        User::factory()->role($role)->create([
            'username' => $username,
            'email' => str_replace('_', '.', $username).'@example.com',
            'tenant_id' => $tenant?->id,
            ...$platformName,
        ]);
    }

    /**
     * Create one of the agency's customers unless one with the username
     * already exists, keeping the factory's realistic random name.
     */
    private function seedCustomer(string $username, Tenant $tenant): void
    {
        if (User::withTrashed()->where('username', $username)->exists()) {
            return;
        }

        User::factory()->role(Role::Customer)->for($tenant)->create([
            'username' => $username,
            'email' => $username.'@example.com',
        ]);
    }

    /**
     * Get the usernames of the agency's demo customers, enough for the booking
     * and wishlist seeders to spread their mock data across.
     *
     * @return list<string>
     */
    private function customersOf(string $tenantId): array
    {
        return match ($tenantId) {
            'dumaguete' => ['customer', 'beatriz', 'tomas', 'carmela', 'julio'],
            'zamboanguita' => ['nadia', 'ramon', 'isabel', 'diego'],
            'tanjay' => ['sofia', 'marco', 'elena', 'gabriel'],
            'dauin' => ['rafael', 'liza', 'miguel', 'andrea'],
            'bayawan' => ['paolo', 'teresa', 'ramil', 'joy'],
            default => [],
        };
    }
}
