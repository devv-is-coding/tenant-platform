<?php

use App\Actions\Catalog\ListingApprovalAction;
use App\Enums\AuditAction;
use App\Enums\ItemStatus;
use App\Enums\PlanTier;
use App\Enums\Role;
use App\Events\ListingDecided;
use App\Models\AuditEvent;
use App\Models\Item;
use App\Models\Settings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * An agency on Basic (which has Catalog), with tenancy started in it.
 */
function catalogAgency(): Tenant
{
    $tenant = tenantOnDomain(tier: PlanTier::Basic);
    tenancy()->initialize($tenant);

    return $tenant;
}

/**
 * The listing's status, and the audit trail it left, as [action, old, new].
 *
 * @return array{status: ItemStatus, trail: list<array{0: string, 1: int, 2: int}>}
 */
function listingTrail(Item $item): array
{
    return [
        'status' => $item->fresh()?->item_status,
        'trail' => AuditEvent::query()->where(['subject_type' => 'item', 'subject_id' => $item->id])->orderBy('id')->get()
            ->map(fn (AuditEvent $event): array => [$event->action->value, $event->changes['item_status']['old'], $event->changes['item_status']['new']])
            ->all(),
    ];
}

test('a listing goes from draft to pending, approved and published, each step audited with who took it', function () {
    $tenant = catalogAgency();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $item = Item::factory()->create(['created_by' => $staff->id]);
    $workflow = app(ListingApprovalAction::class);

    $workflow->submit($staff, $item);
    $workflow->approve($manager, $item);
    $workflow->publish($manager, $item);

    expect(listingTrail($item))->toBe([
        'status' => ItemStatus::Published,
        'trail' => [
            ['item.submitted', ItemStatus::Draft->value, ItemStatus::Pending->value],
            ['item.approved', ItemStatus::Pending->value, ItemStatus::Approved->value],
            ['item.published', ItemStatus::Approved->value, ItemStatus::Published->value],
        ],
    ])->and(AuditEvent::query()->orderBy('id')->pluck('actor_id')->all())->toBe([$staff->id, $manager->id, $manager->id])
        ->and($item->fresh()?->only(['approved_by']))->toBe(['approved_by' => $manager->id])
        ->and($item->fresh()?->published_at)->not->toBeNull();
});

test('a rejected listing carries the reason and can be sent again', function () {
    $tenant = catalogAgency();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $item = Item::factory()->pending()->create();
    $workflow = app(ListingApprovalAction::class);

    $workflow->reject($manager, $item, ['rejection_reason' => ' Add the pickup time. ']);
    $rejected = $item->fresh();
    $workflow->submit($staff, $item);

    expect([$rejected?->item_status, $rejected?->rejection_reason, $rejected?->rejected_at !== null])->toBe([ItemStatus::Rejected, 'Add the pickup time.', true])
        ->and(listingTrail($item)['trail'])->toBe([
            ['item.rejected', ItemStatus::Pending->value, ItemStatus::Rejected->value],
            ['item.submitted', ItemStatus::Rejected->value, ItemStatus::Pending->value],
        ])
        ->and(AuditEvent::query()->where('action', AuditAction::ItemRejected)->value('description'))->toBe('Add the pickup time.');
});

test('an approved listing can still be rejected, and a published one taken off sale', function () {
    $tenant = catalogAgency();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $approved = Item::factory()->approved()->create();
    $published = Item::factory()->published()->create();
    $workflow = app(ListingApprovalAction::class);

    $workflow->reject($manager, $approved, ['rejection_reason' => 'Prices changed.']);
    $workflow->unpublish($manager, $published);

    expect($approved->fresh()?->item_status)->toBe(ItemStatus::Rejected)
        ->and($published->fresh()?->only(['item_status', 'published_at']))->toBe(['item_status' => ItemStatus::Approved, 'published_at' => null])
        ->and(listingTrail($published)['trail'])->toBe([['item.unpublished', ItemStatus::Published->value, ItemStatus::Approved->value]]);
});

test('a step the listing\'s status does not allow is refused, and nothing changes', function (Closure $makeItem, string $step, string $message) {
    $tenant = catalogAgency();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $item = $makeItem();

    expect(fn () => app(ListingApprovalAction::class)->{$step}($manager, $item, ['rejection_reason' => 'Fix it.']))
        ->toThrow(ValidationException::class, $message);

    expect([$item->fresh()?->item_status, AuditEvent::query()->count()])->toBe([$item->item_status, 0]);
})->with([
    'publishing a draft' => [fn () => Item::factory()->create(['name' => 'Bohol Day Tour']), 'publish', 'Bohol Day Tour is draft, so it cannot be published.'],
    'publishing a listing still waiting' => [fn () => Item::factory()->pending()->create(['name' => 'Bohol Day Tour']), 'publish', 'Bohol Day Tour is pending approval, so it cannot be published.'],
    'approving a draft' => [fn () => Item::factory()->create(['name' => 'Bohol Day Tour']), 'approve', 'Bohol Day Tour is draft, so it cannot be approved.'],
    'approving a published listing' => [fn () => Item::factory()->published()->create(['name' => 'Bohol Day Tour']), 'approve', 'Bohol Day Tour is published, so it cannot be approved.'],
    'rejecting a draft' => [fn () => Item::factory()->create(['name' => 'Bohol Day Tour']), 'reject', 'Bohol Day Tour is draft, so it cannot be rejected.'],
    'sending a published listing again' => [fn () => Item::factory()->published()->create(['name' => 'Bohol Day Tour']), 'submit', 'Bohol Day Tour is published, so it cannot be sent for approval.'],
    'taking an approved listing off sale' => [fn () => Item::factory()->approved()->create(['name' => 'Bohol Day Tour']), 'unpublish', 'Bohol Day Tour is approved, so it cannot be taken off sale.'],
]);

test('a rejection needs a reason', function () {
    $tenant = catalogAgency();
    $item = Item::factory()->pending()->create();

    expect(fn () => app(ListingApprovalAction::class)->reject(User::factory()->role(Role::Manager)->for($tenant)->create(), $item, ['rejection_reason' => ' ']))
        ->toThrow(ValidationException::class, 'Tell the writer what to change.');

    expect($item->fresh()?->item_status)->toBe(ItemStatus::Pending);
});

test('staff cannot approve a listing, their own included', function () {
    $tenant = catalogAgency();
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $item = Item::factory()->create(['created_by' => $staff->id]);
    $workflow = app(ListingApprovalAction::class);
    $workflow->submit($staff, $item);

    expect(fn () => $workflow->approve($staff, $item))->toThrow(AuthorizationException::class);

    expect($item->fresh()?->item_status)->toBe(ItemStatus::Pending);
});

test('a manager may approve a listing they wrote', function () {
    $tenant = catalogAgency();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $item = Item::factory()->create(['created_by' => $manager->id]);
    $workflow = app(ListingApprovalAction::class);

    $workflow->submit($manager, $item);
    $workflow->approve($manager, $item);

    expect($item->fresh()?->item_status)->toBe(ItemStatus::Approved);
});

test('an agency that asks for no approval publishes a listing the moment it is sent', function () {
    $tenant = catalogAgency();
    Settings::current()->update(['requires_listing_approval' => false]);
    $staff = User::factory()->role(Role::Staff)->for($tenant)->create();
    $item = Item::factory()->create();

    app(ListingApprovalAction::class)->submit($staff, $item);

    expect(listingTrail($item))->toBe([
        'status' => ItemStatus::Published,
        'trail' => [['item.published', ItemStatus::Draft->value, ItemStatus::Published->value]],
    ])->and($item->fresh()?->item_status->isVisibleToCustomers())->toBeTrue();
});

test('the writer is told of each decision, never of a step that is not one', function () {
    $tenant = catalogAgency();
    $manager = User::factory()->role(Role::Manager)->for($tenant)->create();
    $approving = Item::factory()->pending()->create();
    $rejecting = Item::factory()->pending()->create();
    Event::fake([ListingDecided::class]);
    $workflow = app(ListingApprovalAction::class);

    $workflow->approve($manager, $approving);
    $workflow->reject($manager, $rejecting, ['rejection_reason' => 'Fix it.']);
    $workflow->publish($manager, $approving);

    Event::assertDispatchedTimes(ListingDecided::class, 2);
});
