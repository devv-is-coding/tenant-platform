<?php

use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Models\Item;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Wishlist\WishlistService;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travelTo;

test('a customer saves a published item to their wishlist, once however often they save it', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $item = withinTenant($tenant, fn (): Item => Item::factory()->published()->create());

    withinTenant($tenant, function () use ($customer, $item): void {
        app(WishlistService::class)->save($customer, $item);
        app(WishlistService::class)->save($customer, $item);
    });

    expect(withinTenant($tenant, fn (): array => WishlistItem::query()->get(['customer_id', 'item_id'])->toArray()))
        ->toBe([['customer_id' => $customer->id, 'item_id' => $item->id]]);
});

test('an item not on sale cannot be saved', function (ItemStatus $status) {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    $item = withinTenant($tenant, fn (): Item => Item::factory()->create(['name' => 'Japan Discovery', 'item_status' => $status]));

    expect(fn (): WishlistItem => withinTenant($tenant, fn (): WishlistItem => app(WishlistService::class)->save($customer, $item)))
        ->toThrow(ValidationException::class, 'Japan Discovery is not available.');

    expect(withinTenant($tenant, fn (): int => WishlistItem::withTrashed()->count()))->toBe(0);
})->with(collect(ItemStatus::cases())->reject(fn (ItemStatus $status): bool => $status->isVisibleToCustomers())->mapWithKeys(fn (ItemStatus $status): array => [$status->name => [$status]])->all());

test('removing an item and saving it again brings the same row back', function () {
    $tenant = tenantOnDomain();
    $customer = User::factory()->role(Role::Customer)->for($tenant)->create();
    [$item, $first] = withinTenant($tenant, function () use ($customer): array {
        $item = Item::factory()->published()->create();
        $first = app(WishlistService::class)->save($customer, $item);
        app(WishlistService::class)->remove($customer, $item);

        return [$item, $first];
    });

    $again = withinTenant($tenant, fn (): WishlistItem => app(WishlistService::class)->save($customer, $item));

    expect($again->id)->toBe($first->id)
        ->and($again->trashed())->toBeFalse()
        ->and(withinTenant($tenant, fn (): int => WishlistItem::withTrashed()->count()))->toBe(1);
});

test('the wishlist shows only the customer\'s own items still on sale, most recently saved first', function () {
    $tenant = tenantOnDomain();
    [$customer, $other] = User::factory()->count(2)->role(Role::Customer)->for($tenant)->create()->all();
    [$older, $newer] = withinTenant($tenant, function () use ($customer, $other): array {
        [$older, $newer, $unpublished, $deleted, $theirs] = Item::factory()->count(5)->published()->create()->all();
        travelTo('2026-11-20 09:00:00');
        app(WishlistService::class)->save($customer, $older);
        travelTo('2026-11-21 09:00:00');
        foreach ([$newer, $unpublished, $deleted] as $item) {
            app(WishlistService::class)->save($customer, $item);
        }
        app(WishlistService::class)->save($other, $theirs);
        $unpublished->update(['item_status' => ItemStatus::Draft]);
        $deleted->delete();

        return [$older, $newer];
    });

    $saved = withinTenant($tenant, fn (): array => app(WishlistService::class)->savedBy($customer)->pluck('item.id')->all());

    expect($saved)->toBe([$newer->id, $older->id]);
});

test('deleting an item for good takes it off every wishlist', function () {
    $tenant = tenantOnDomain();
    [$item, $kept] = withinTenant($tenant, fn (): array => [Item::factory()->published()->create(), Item::factory()->published()->create()]);
    withinTenant($tenant, function () use ($item, $kept): void {
        WishlistItem::factory()->count(2)->for($item)->create();
        WishlistItem::factory()->for($kept)->create();
    });

    withinTenant($tenant, fn (): ?bool => $item->forceDelete());

    expect(withinTenant($tenant, fn (): array => WishlistItem::withTrashed()->pluck('item_id')->all()))->toBe([$kept->id]);
});
