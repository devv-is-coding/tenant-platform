<?php

namespace App\Actions\Catalog;

use App\Enums\AuditAction;
use App\Enums\ItemStatus;
use App\Events\ListingDecided;
use App\Models\Item;
use App\Models\Settings;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The listing approval workflow (TM-1): staff send a listing for approval, a
 * manager approves or rejects it with a reason, and publishing it is a step
 * of its own, so an approved listing stays internal until someone puts it on
 * sale. Only a published listing is ever shown to customers.
 *
 * Every step follows ItemStatus::allowedTransitions(), locks the listing's
 * row first so two managers deciding at once cannot both move it, and
 * writes its own audit event. An agency that turned off
 * `requires_listing_approval` publishes a listing the moment it is sent.
 */
class ListingApprovalAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Send a draft or rejected listing for a manager's approval, or publish
     * it at once when the agency does not ask for one.
     *
     * @throws ValidationException for a listing that cannot be sent from its status
     */
    public function submit(User $actor, Item $item): Item
    {
        Gate::forUser($actor)->authorize('submit', $item);

        return DB::transaction(function () use ($actor, $item): Item {
            $locked = $this->lockFor($item, ItemStatus::Pending, [ItemStatus::Draft, ItemStatus::Rejected], __('sent for approval'));
            $before = $locked->item_status;

            if (! Settings::current()->requires_listing_approval) {
                $locked->forceFill([
                    'item_status' => ItemStatus::Published,
                    'submitted_at' => now(),
                    'approved_at' => now(),
                    'approved_by' => $actor->id,
                    'published_at' => now(),
                ])->save();

                $this->record($actor, AuditAction::ItemPublished, $locked, $before, __(':item published without approval; the agency does not ask for one.', ['item' => $locked->name]));

                return $locked;
            }

            $locked->forceFill(['item_status' => ItemStatus::Pending, 'submitted_at' => now()])->save();

            $this->record($actor, AuditAction::ItemSubmitted, $locked, $before, __(':item sent for approval.', ['item' => $locked->name]));

            return $locked;
        });
    }

    /**
     * Approve a listing waiting for a decision. It is not on sale until it
     * is published.
     *
     * @throws ValidationException for a listing not waiting for a decision
     */
    public function approve(User $actor, Item $item): Item
    {
        Gate::forUser($actor)->authorize('decide', $item);

        return DB::transaction(function () use ($actor, $item): Item {
            $locked = $this->lockFor($item, ItemStatus::Approved, [ItemStatus::Pending], __('approved'));
            $before = $locked->item_status;

            $locked->forceFill([
                'item_status' => ItemStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ])->save();

            $this->record($actor, AuditAction::ItemApproved, $locked, $before, __(':item approved.', ['item' => $locked->name]));

            ListingDecided::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Send a waiting or approved listing back to its writer with a reason,
     * editable again.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException for no reason, or a listing that cannot be rejected
     */
    public function reject(User $actor, Item $item, array $input): Item
    {
        Gate::forUser($actor)->authorize('decide', $item);

        $validated = Validator::make($input, ['rejection_reason' => ['required', 'string', 'max:255']], [
            'rejection_reason.required' => __('Tell the writer what to change.'),
        ])->validate();

        return DB::transaction(function () use ($actor, $item, $validated): Item {
            $locked = $this->lockFor($item, ItemStatus::Rejected, [ItemStatus::Pending, ItemStatus::Approved], __('rejected'));
            $before = $locked->item_status;

            $locked->forceFill([
                'item_status' => ItemStatus::Rejected,
                'rejection_reason' => trim($validated['rejection_reason']),
                'rejected_at' => now(),
                'approved_by' => $actor->id,
            ])->save();

            $this->record($actor, AuditAction::ItemRejected, $locked, $before, $locked->rejection_reason);

            ListingDecided::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Put an approved listing on sale.
     *
     * @throws ValidationException for a listing not approved
     */
    public function publish(User $actor, Item $item): Item
    {
        Gate::forUser($actor)->authorize('publish', $item);

        return DB::transaction(function () use ($actor, $item): Item {
            $locked = $this->lockFor($item, ItemStatus::Published, [ItemStatus::Approved], __('published'));
            $before = $locked->item_status;

            $locked->forceFill(['item_status' => ItemStatus::Published, 'published_at' => now()])->save();

            $this->record($actor, AuditAction::ItemPublished, $locked, $before, __(':item published.', ['item' => $locked->name]));

            return $locked;
        });
    }

    /**
     * Take a published listing off sale, back to approved. Bookings already
     * made on it stand.
     *
     * @throws ValidationException for a listing not published
     */
    public function unpublish(User $actor, Item $item): Item
    {
        Gate::forUser($actor)->authorize('publish', $item);

        return DB::transaction(function () use ($actor, $item): Item {
            $locked = $this->lockFor($item, ItemStatus::Approved, [ItemStatus::Published], __('taken off sale'));
            $before = $locked->item_status;

            $locked->forceFill(['item_status' => ItemStatus::Approved, 'published_at' => null])->save();

            $this->record($actor, AuditAction::ItemUnpublished, $locked, $before, __(':item taken off sale.', ['item' => $locked->name]));

            return $locked;
        });
    }

    /**
     * Lock the listing and refuse a step its status does not allow, so a
     * listing someone else just moved is not moved again. Each step names the
     * statuses it starts from, because two steps can end in the same status
     * (approving and unpublishing both end in Approved); the move itself must
     * also be one ItemStatus allows.
     *
     * @param  list<ItemStatus>  $from
     *
     * @throws ValidationException
     */
    private function lockFor(Item $item, ItemStatus $to, array $from, string $verb): Item
    {
        $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        $status = $locked->item_status;

        if (! in_array($status, $from, true) || ! in_array($to, $status->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => __(':item is :status, so it cannot be :verb.', [
                    'item' => $locked->name,
                    'status' => mb_strtolower($status->label()),
                    'verb' => $verb,
                ]),
            ]);
        }

        return $locked;
    }

    /**
     * Record one step in the agency's audit log, with the status it moved from.
     */
    private function record(User $actor, AuditAction $action, Item $item, ItemStatus $before, ?string $description): void
    {
        $this->auditRecorder->recordTenantEvent(
            $action,
            $actor,
            $description,
            ['item_status' => ['old' => $before->value, 'new' => $item->item_status->value]],
            $item,
        );
    }
}
