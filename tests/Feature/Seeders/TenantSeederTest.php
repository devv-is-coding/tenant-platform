<?php

use App\Models\Tenant;
use Database\Seeders\TenantSeeder;

use function Pest\Laravel\seed;

test('the seeded agencies store a name and a home locality, the same keys onboarding stores', function () {
    seed(TenantSeeder::class);

    $dumaguete = Tenant::findOrFail('dumaguete');

    expect($dumaguete->name)->toBe('Dumaguete City Travel & Tours')
        ->and($dumaguete->locality)->toBe('Dumaguete City')
        ->and($dumaguete->currency)->toBe('PHP')
        ->and($dumaguete->getAttribute('locality_type'))->toBeNull();
});

test('seeding again skips the agencies that already exist', function () {
    seed(TenantSeeder::class);

    seed(TenantSeeder::class);

    expect(Tenant::query()->count())->toBe(5);
});
