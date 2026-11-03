<?php

use App\Enums\Role;
use App\Models\Booking;
use App\Models\InventoryAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\AvailabilityService;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
 * The true race (ROADMAP.md → Concurrency, layer 3): separate PHP processes
 * book the last unit at the same instant, against MariaDB, where the row lock
 * is real. Exactly one may win; every other gets a clean "unavailable".
 *
 * Not part of the default run (phpunit.xml's defaultTestSuite): it needs a
 * MariaDB server and is slow. Run it in the app container:
 *
 *     docker compose exec app php artisan test --testsuite=Concurrency
 *
 * It never touches the app's own data: the central tables go in a database of
 * their own, and the agency is a throwaway one; both are dropped afterwards.
 */

const RACE_CENTRAL_DATABASE = 'tenant_race_central';
const RACERS = 6;

/**
 * Point the app at a fresh central database on the real MariaDB server, or
 * skip where there is none. The name falls inside the app user's `tenant%`
 * grant (docker/mariadb/init), the only databases it may create.
 */
function useRaceDatabase(): string
{
    $driver = (string) getenv('DB_CONNECTION');

    if (! in_array($driver, ['mariadb', 'mysql'], true)) {
        TestCase::markTestSkipped('Needs MariaDB: on SQLite lockForUpdate() compiles to nothing (F1), so a race there would prove nothing about the lock.');
    }

    $server = [
        'host' => (string) getenv('DB_HOST'),
        'port' => (string) getenv('DB_PORT'),
        'username' => (string) getenv('DB_USERNAME'),
        'password' => (string) getenv('DB_PASSWORD'),
    ];

    config([
        'database.connections.race-server' => [...config()->array("database.connections.{$driver}"), ...$server, 'database' => null],
        "database.connections.{$driver}" => [...config()->array("database.connections.{$driver}"), ...$server, 'database' => RACE_CENTRAL_DATABASE],
        'database.default' => $driver,
        'tenancy.database.central_connection' => $driver,
    ]);

    DB::connection('race-server')->statement('DROP DATABASE IF EXISTS `'.RACE_CENTRAL_DATABASE.'`');
    DB::connection('race-server')->statement('CREATE DATABASE `'.RACE_CENTRAL_DATABASE.'`');
    DB::purge($driver);

    Artisan::call('migrate', ['--database' => $driver, '--force' => true]);

    return $driver;
}

test('of several processes booking the last unit at once, exactly one gets it and the rest are told it is gone', function () {
    $driver = useRaceDatabase();
    $tenant = Tenant::factory()->create();

    try {
        $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
        $seat = withinTenant($tenant, fn () => seatOnTour(stock: 1));
        $startAt = microtime(true) + 4;

        $results = Process::pool(function (Pool $pool) use ($driver, $tenant, $seat, $customer, $startAt): void {
            foreach (range(1, RACERS) as $racer) {
                $pool->path(base_path())
                    ->env(['DB_CONNECTION' => $driver, 'DB_DATABASE' => RACE_CENTRAL_DATABASE])
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Concurrency/fixtures/book-last-unit.php', $tenant->id, (string) $seat->id, (string) $customer->id, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($results->collect())->map(fn ($result): string => trim($result->output()))->countBy()->sortKeys()->all();

        $after = withinTenant($tenant, fn (): array => [
            'bookings' => Booking::count(),
            'held' => InventoryAllocation::count(),
            'available' => app(AvailabilityService::class)->availableFor($seat->fresh()),
        ]);

        expect($outcomes)->toBe(['booked' => 1, 'unavailable' => RACERS - 1])
            ->and($after)->toBe(['bookings' => 1, 'held' => 1, 'available' => 0]);
    } finally {
        tenancy()->end();
        User::withTrashed()->where('tenant_id', $tenant->id)->forceDelete();
        $tenant->forceDelete();
        DB::connection('race-server')->statement('DROP DATABASE IF EXISTS `'.RACE_CENTRAL_DATABASE.'`');
    }
});
