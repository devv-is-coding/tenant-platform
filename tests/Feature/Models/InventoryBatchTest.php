<?php

use App\Enums\InventoryBatchReason;
use App\Enums\Role;
use App\Models\InventoryBatch;
use App\Models\Item;
use App\Models\Option;
use App\Models\Tenant;
use App\Models\User;

test('defect 8: a batch added by a user who is not one of the agency\'s staff is refused', function (Closure $makeUser) {
    $tenant = tenantOnDomain();
    $userId = $makeUser($tenant);

    withinTenant($tenant, function () use ($userId): void {
        expect(fn () => InventoryBatch::factory()->create(['added_by' => $userId]))
            ->toThrow(InvalidArgumentException::class, "User [{$userId}] is not one of this agency's staff, so cannot have added the batch.");

        expect(InventoryBatch::count())->toBe(0);
    });
})->with([
    // The legacy's `added_by = 1`: an id no user has.
    'a user who does not exist' => [fn (Tenant $tenant): int => (int) User::withTrashed()->max('id') + 1],
    'another agency\'s staff' => [fn (Tenant $tenant): int => User::factory()->role(Role::Staff)->for(tenantOnDomain())->create()->id],
    'the agency\'s own customer' => [fn (Tenant $tenant): int => User::factory()->role(Role::Customer)->for($tenant)->create()->id],
    'the super admin' => [fn (Tenant $tenant): int => User::factory()->superAdmin()->create()->id],
]);

test('a batch records which of the agency\'s staff added it', function (Role $role) {
    $tenant = tenantOnDomain();
    $staff = User::factory()->role($role)->for($tenant)->create();

    $addedBy = withinTenant($tenant, fn (): ?int => InventoryBatch::factory()->create(['added_by' => $staff->id])->fresh()?->added_by);

    expect($addedBy)->toBe($staff->id);
})->with(Role::tenantRoles());

test('an Unlimited option has no inventory batches', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        $visa = Option::factory()->unlimited()->for(Item::factory()->visa())->create();

        expect(fn () => InventoryBatch::factory()->for($visa)->create())
            ->toThrow(LogicException::class, 'An Unlimited option tracks no stock, so it has no inventory batches.');
    });
});

test('a batch\'s quantity is never zero, and its sign follows its reason', function (int $quantity, InventoryBatchReason $reason, string $message) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($quantity, $reason, $message): void {
        expect(fn () => InventoryBatch::factory()->create(['quantity' => $quantity, 'reason' => $reason]))
            ->toThrow(InvalidArgumentException::class, $message);
    });
})->with([
    'no units' => [0, InventoryBatchReason::SeasonalAddition, 'A batch adds or removes at least one unit.'],
    'opening stock taken away' => [-5, InventoryBatchReason::InitialStock, 'A Initial stock batch has a positive quantity, not -5.'],
    'a correction that adds' => [3, InventoryBatchReason::Correction, 'A Correction batch has a negative quantity, not 3.'],
    'maintenance that adds' => [1, InventoryBatchReason::Maintenance, 'A Withdrawn for maintenance batch has a negative quantity, not 1.'],
]);

test('a batch\'s effective range cannot end before it starts', function () {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function (): void {
        expect(fn () => InventoryBatch::factory()->create(['effective_from' => '2026-12-31', 'effective_to' => '2026-12-01']))
            ->toThrow(InvalidArgumentException::class, 'A range cannot end (2026-12-01) before it starts (2026-12-31).');
    });
});

test('a written batch\'s option, quantity and reason never change, though its notes may', function (Closure $change) {
    $tenant = tenantOnDomain();

    withinTenant($tenant, function () use ($change): void {
        $batch = InventoryBatch::factory()->create();

        expect(fn () => $batch->update($change()))
            ->toThrow(LogicException::class, 'An inventory batch cannot be changed once written; add a Correction batch instead.');

        $batch->refresh()->update(['notes' => 'Counted again on 2026-10-29']);

        expect($batch->fresh())
            ->quantity->toBe(10)
            ->reason->toBe(InventoryBatchReason::InitialStock)
            ->notes->toBe('Counted again on 2026-10-29');
    });
})->with([
    'the quantity' => [fn () => ['quantity' => 12]],
    'the reason' => [fn () => ['reason' => InventoryBatchReason::SeasonalAddition]],
    'the option' => [fn () => ['option_id' => Option::factory()->create()->id]],
]);
