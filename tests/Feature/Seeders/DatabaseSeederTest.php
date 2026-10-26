<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\seed;

test('every seeded customer belongs to one agency, and only the super admin belongs to none', function () {
    seed(DatabaseSeeder::class);

    expect(User::query()->whereNull('tenant_id')->pluck('username')->all())->toBe(['superadmin'])
        ->and(User::query()->where('username', 'customer')->value('tenant_id'))->toBe('dumaguete');
});

test('each agency\'s seeded bookings, wishlists and notifications are its own customers\'', function () {
    seed(DatabaseSeeder::class);

    // Iterated on the builder: the stancl base model's TenantCollection is not
    // generic, so a fetched collection would lose the Tenant type.
    Tenant::query()->each(function (Tenant $tenant): void {
        $customerIds = $tenant->customers()->pluck('id')->all();

        $seeded = withinTenant($tenant, fn (): array => [
            'booking' => DB::table('bookings')->distinct()->pluck('customer_id')->all(),
            'wishlist' => DB::table('wishlist_items')->distinct()->pluck('customer_id')->all(),
            'notification' => DB::table('notifications')->distinct()->pluck('user_id')->all(),
        ]);

        foreach ($seeded as $table => $ids) {
            expect($ids)->not->toBeEmpty("{$tenant->id} has no seeded {$table} rows")
                ->and(array_diff($ids, $customerIds))->toBe([], "{$tenant->id} has a {$table} row for someone else's customer");
        }
    });

    expect(User::query()->where('role', Role::Customer)->count())->toBe(21);
});
