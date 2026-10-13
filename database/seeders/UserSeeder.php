<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed one platform-wide super admin and customer, plus an admin, manager and
     * staff member for every tenant. Run TenantSeeder first.
     *
     * Users are keyed by username so the seeder can be re-run. The role is always
     * set explicitly because the column default is super admin. Every seeded
     * account uses the factory's default password, "password".
     */
    public function run(): void
    {
        $this->seedUser('superadmin', Role::SuperAdmin);
        $this->seedUser('customer', Role::Customer);

        // A handful of extra named customers, so marketplace mock data (wishlists,
        // bookings, reviews) has more than one customer to spread across.
        foreach (['beatriz', 'tomas', 'carmela', 'julio', 'nadia'] as $username) {
            $this->seedCustomer($username);
        }

        // Iterated on the builder: the stancl base model's TenantCollection is not
        // generic, so a fetched collection would lose the Tenant type.
        Tenant::query()->each(function (Tenant $tenant): void {
            foreach ([Role::Admin, Role::Manager, Role::Staff] as $role) {
                $this->seedUser("{$tenant->id}_".strtolower($role->name), $role, $tenant);
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
     * Create a customer unless one with the username already exists, keeping
     * the factory's realistic random name rather than the role-labelled name
     * used for the fixed demo accounts.
     */
    private function seedCustomer(string $username): void
    {
        if (User::withTrashed()->where('username', $username)->exists()) {
            return;
        }

        User::factory()->role(Role::Customer)->create([
            'username' => $username,
            'email' => $username.'@example.com',
        ]);
    }
}
