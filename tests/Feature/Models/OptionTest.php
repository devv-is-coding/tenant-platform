<?php

use App\Enums\AvailabilityMode;
use App\Models\Item;
use App\Models\Option;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

test('defect 2: the legacy prices round-trip exactly, as integer minor units', function (string $legacyPrice, int $minor) {
    $tenant = tenantOnDomain();

    [$stored, $read] = withinTenant($tenant, function () use ($legacyPrice): array {
        $option = Option::factory()->create(['price' => Money::parse($legacyPrice, 'PHP')]);

        return [DB::table('options')->where('id', $option->id)->value('price_minor'), Option::query()->findOrFail($option->id)->price];
    });

    expect((int) $stored)->toBe($minor)
        ->and($read->minor)->toBe($minor)
        ->and($read->toDecimal())->toBe($legacyPrice);
})->with([
    'Solo' => ['499.00', 49900],
    'Couple' => ['699.00', 69900],
    'Family (5)' => ['999.00', 99900],
    'Room 101' => ['199.00', 19900],
    'Standard Double' => ['160.00', 16000],
]);

test('the price is in the agency\'s own currency, read from the agency, never stored on the option', function () {
    $tenant = tenantOnDomain();
    $tenant->update(['currency' => 'JPY']);

    $price = withinTenant($tenant, fn () => Option::factory()->create(['price' => Money::ofMinor(12000, 'JPY')])->fresh()?->price);

    expect($price?->currency)->toBe('JPY')
        ->and($price?->minor)->toBe(12000);
});

test('a price in another currency than the agency\'s is refused', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => Option::factory()->create(['price' => Money::ofMinor(49900, 'USD')]))
            ->toThrow(InvalidArgumentException::class, '[price] is in PHP, the agency\'s currency, not USD.');
    });
});

test('only a Money can be given as the price, never a bare number', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => Option::factory()->create(['price' => 499.0]))
            ->toThrow(InvalidArgumentException::class, '[price] only accepts a Money');
    });
});

test('only a hotel sells by the night', function (Closure $makeItem) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($makeItem): void {
        expect(fn () => Option::factory()->nightly()->for($makeItem())->create())
            ->toThrow(LogicException::class, 'Only a hotel sells by the night.');

        expect(Option::count())->toBe(0);
    });
})->with([
    'a tour' => [fn () => Item::factory()->create()],
    'a visa' => [fn () => Item::factory()->visa()->create()],
]);

test('a hotel can sell both a room by the night and a fixed-capacity package', function () {
    $tenant = tenantOnDomain();

    $modes = withinTenant($tenant, function (): array {
        $hotel = Item::factory()->hotel()->create();
        Option::factory()->nightly()->for($hotel)->create();
        Option::factory()->for($hotel)->create(['name' => 'New Year package']);

        return $hotel->options()->pluck('availability_mode')->all();
    });

    expect($modes)->toBe([AvailabilityMode::Nightly, AvailabilityMode::Capacity]);
});

test('a length of stay belongs only on a per-night option, and must make sense', function (Closure $makeOption, string $message) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($makeOption, $message): void {
        expect($makeOption)->toThrow(InvalidArgumentException::class, $message);
    });
})->with([
    'nights on a tour seat' => [fn () => Option::factory()->create(['min_nights' => 2]), 'A Fixed capacity option has no length of stay; only a per-night one does.'],
    'nights on a visa' => [fn () => Option::factory()->unlimited()->for(Item::factory()->visa())->create(['max_nights' => 5]), 'A Unlimited option has no length of stay; only a per-night one does.'],
    'a stay of no nights' => [fn () => Option::factory()->nightly()->for(Item::factory()->hotel())->create(['min_nights' => 0]), 'A stay is at least one night.'],
    'a shortest stay longer than the longest' => [fn () => Option::factory()->nightly()->for(Item::factory()->hotel())->create(['min_nights' => 7, 'max_nights' => 3]), 'A stay cannot be at least 7 nights but at most 3.'],
]);

test('an option stores no stock and no currency: both are derived', function () {
    $tenant = tenantOnDomain();

    $columns = withinTenant($tenant, fn (): array => DB::getSchemaBuilder()->getColumnListing('options'));

    expect($columns)->not->toContain('available_quantity')
        ->not->toContain('currency')
        ->toContain('price_minor');
});

test('an agency\'s options are in its own database', function () {
    $dumaguete = tenantOnDomain();
    $tanjay = tenantOnDomain();

    withinTenant($dumaguete, fn () => Option::factory()->create(['name' => 'Dumaguete Solo']));

    expect(withinTenant($tanjay, fn (): int => Option::count()))->toBe(0);
});
