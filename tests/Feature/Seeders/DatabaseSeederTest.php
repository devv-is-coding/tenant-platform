<?php

use App\Enums\Role;
use App\Models\HotelProfile;
use App\Models\Item;
use App\Models\ItineraryStop;
use App\Models\Media;
use App\Models\Option;
use App\Models\Tenant;
use App\Models\TourProfile;
use App\Models\User;
use App\Models\VisaProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
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

test('every seeded catalog row obeys the models\' own rules, and its media are on the agency\'s disk', function () {
    seed(DatabaseSeeder::class);

    Tenant::query()->each(function (Tenant $tenant): void {
        withinTenant($tenant, function () use ($tenant): void {
            // The seeder writes raw rows; saving each through its model runs
            // the checks a real write would (type, date ranges, coordinates,
            // modes and lengths of stay).
            foreach ([TourProfile::class, VisaProfile::class, HotelProfile::class, ItineraryStop::class, Option::class] as $model) {
                $model::query()->each(fn (Model $row) => $row->save());
            }

            expect(Item::count())->toBeGreaterThan(0, "{$tenant->id} has no seeded items")
                ->and(Media::query()->where('disk', '!=', Media::DISK)->count())->toBe(0, "{$tenant->id} has media on another disk");
        });
    });
});
