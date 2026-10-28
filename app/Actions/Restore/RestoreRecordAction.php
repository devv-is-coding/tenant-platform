<?php

namespace App\Actions\Restore;

use App\Enums\AuditAction;
use App\Enums\RestoreRequestStatus;
use App\Models\Domain;
use App\Models\PlatformAuditEvent;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class RestoreRecordAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Bring back one of an agency's deleted records - a user, subscription,
     * invoice or payment - with whatever its own delete took along with it.
     *
     * A restore reverses its own cascade only: the children deleted *with* the
     * record, read from its delete's audit row, never ones deleted earlier on
     * purpose. A record whose parent is still deleted is refused, so nothing
     * comes back orphaned. A subscription stays Cancelled: the delete ended it,
     * and putting the agency back on a plan is a separate decision.
     *
     * When the restore answers an agency admin's request, the request is
     * closed as Restored in the same transaction.
     */
    public function handle(
        User $actor,
        Tenant $tenant,
        User|Subscription|SubscriptionInvoice|SubscriptionPayment $record,
        ?RestoreRequest $request = null,
    ): void {
        if ($tenant->trashed()) {
            $this->refuse(__(':agency is deleted. Restore the agency first.', ['agency' => $tenant->name ?? $tenant->id]));
        }

        $record->getConnection()->transaction(function () use ($actor, $tenant, $record, $request): void {
            if ($request !== null && RestoreRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail()->status !== RestoreRequestStatus::Pending) {
                $this->refuse(__('This request has already been decided.'));
            }

            try {
                $restored = match (true) {
                    $record instanceof User => $this->restoreUser($record),
                    $record instanceof Subscription => $this->restoreSubscription($record),
                    $record instanceof SubscriptionInvoice => $this->restoreInvoice($record),
                    $record instanceof SubscriptionPayment => $this->restorePayment($record),
                };
            } catch (UniqueConstraintViolationException) {
                $this->refuse(__(':record cannot be restored: something now in use has its unique details.', ['record' => $this->labelFor($record)]));
            }

            $this->auditRecorder->recordPlatformEvent(
                $this->restoredActionFor($record),
                $actor,
                $tenant,
                $request?->reason,
                subject: $record,
                context: array_filter(['restored' => $restored, 'restore_request_id' => $request?->id]),
            );

            $request?->update([
                'status' => RestoreRequestStatus::Restored,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
        });
    }

    /**
     * Describe a record the way the console names it.
     */
    public function labelFor(User|Subscription|SubscriptionInvoice|SubscriptionPayment $record): string
    {
        return match (true) {
            $record instanceof User => $record->full_name,
            $record instanceof Subscription => $record->plan_name,
            $record instanceof SubscriptionInvoice, $record instanceof SubscriptionPayment => $record->reference,
        };
    }

    /**
     * Name the kind of record, as a list of deleted records shows it.
     */
    public function kindFor(User|Subscription|SubscriptionInvoice|SubscriptionPayment $record): string
    {
        return match (true) {
            $record instanceof User => __('User'),
            $record instanceof Subscription => __('Subscription'),
            $record instanceof SubscriptionInvoice => __('Invoice'),
            $record instanceof SubscriptionPayment => __('Payment'),
        };
    }

    /**
     * Get the agency's deleted staff, subscriptions, invoices and payments,
     * most recently deleted first: what the console can restore and the
     * agency's admin can ask for. Never its customers, and never another
     * agency's records.
     *
     * @return list<array{type: string, id: int, kind: string, name: string, deleted_at: CarbonInterface|null}>
     */
    public function deletedRecordsOf(Tenant $tenant): array
    {
        $records = [];

        foreach ([
            'user' => $tenant->staff()->onlyTrashed()->get(),
            'subscription' => $tenant->subscriptions()->onlyTrashed()->get(),
            'invoice' => $tenant->subscriptionInvoices()->onlyTrashed()->get(),
            'payment' => $tenant->subscriptionPayments()->onlyTrashed()->get(),
        ] as $type => $deleted) {
            foreach ($deleted as $record) {
                $records[] = [
                    'type' => $type,
                    'id' => $record->id,
                    'kind' => $this->kindFor($record),
                    'name' => $this->labelFor($record),
                    'deleted_at' => $record->deleted_at,
                ];
            }
        }

        usort($records, fn (array $a, array $b): int => ($b['deleted_at']?->getTimestamp() ?? 0) <=> ($a['deleted_at']?->getTimestamp() ?? 0));

        return $records;
    }

    /**
     * Find one of the agency's deleted records by the type deletedRecordsOf()
     * gives it, or fail with a 404: another agency's record is not found.
     */
    public function findDeleted(Tenant $tenant, string $type, int $id): User|Subscription|SubscriptionInvoice|SubscriptionPayment
    {
        return match ($type) {
            'user' => $tenant->staff()->onlyTrashed()->findOrFail($id),
            'subscription' => $tenant->subscriptions()->onlyTrashed()->findOrFail($id),
            'invoice' => $tenant->subscriptionInvoices()->onlyTrashed()->findOrFail($id),
            'payment' => $tenant->subscriptionPayments()->onlyTrashed()->findOrFail($id),
            default => abort(404),
        };
    }

    /**
     * @return array<string, list<int>>
     */
    private function restoreUser(User $user): array
    {
        $this->ensureTrashed($user);
        $user->restore();

        return [];
    }

    /**
     * @return array<string, list<int>>
     */
    private function restoreSubscription(Subscription $subscription): array
    {
        $this->ensureTrashed($subscription);
        $cascaded = $this->cascadeOf(AuditAction::SubscriptionDeleted, $subscription->getMorphClass(), $subscription->id);

        $subscription->restore();

        return [
            'subscription_invoice' => $this->restoreTrashed(SubscriptionInvoice::class, $cascaded['subscription_invoice'] ?? []),
            'subscription_payment' => $this->restoreTrashed(SubscriptionPayment::class, $cascaded['subscription_payment'] ?? []),
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function restoreInvoice(SubscriptionInvoice $invoice): array
    {
        $this->ensureTrashed($invoice);

        if (! Subscription::query()->whereKey($invoice->subscription_id)->exists()) {
            $this->refuse(__('Invoice :reference belongs to a deleted subscription. Restore the subscription first.', ['reference' => $invoice->reference]));
        }

        $cascaded = $this->cascadeOf(AuditAction::SubscriptionInvoiceDeleted, $invoice->getMorphClass(), $invoice->id);

        $invoice->restore();
        $payments = $this->restoreTrashed(SubscriptionPayment::class, $cascaded['subscription_payment'] ?? []);
        $invoice->recomputeAmountPaid();

        return ['subscription_payment' => $payments];
    }

    /**
     * @return array<string, list<int>>
     */
    private function restorePayment(SubscriptionPayment $payment): array
    {
        $this->ensureTrashed($payment);

        $invoice = SubscriptionInvoice::query()->whereKey($payment->subscription_invoice_id)->first()
            ?? $this->refuse(__('Payment :reference belongs to a deleted invoice. Restore the invoice first.', ['reference' => $payment->reference]));

        // Locked subscription first, invoice second, as billing does.
        Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->first();
        $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $payment->restore();
        $locked->recomputeAmountPaid();

        return [];
    }

    /**
     * Read which rows a delete took with it, from that delete's audit row.
     *
     * @return array<string, list<int>>
     */
    private function cascadeOf(AuditAction $deleteAction, ?string $subjectType, int|string|null $subjectId): array
    {
        $event = PlatformAuditEvent::query()
            ->where('action', $deleteAction)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('id')
            ->first();

        $cascaded = $event?->context['cascaded'] ?? [];

        return is_array($cascaded) ? $cascaded : [];
    }

    /**
     * Restore those of the given rows that are still deleted, returning their ids.
     *
     * @param  class-string<User|Domain|Subscription|SubscriptionInvoice|SubscriptionPayment>  $model
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function restoreTrashed(string $model, array $ids): array
    {
        $trashed = array_values(array_map(intval(...), $model::query()->onlyTrashed()->whereKey($ids)->pluck('id')->all()));

        $model::query()->onlyTrashed()->whereKey($trashed)->restore();

        return $trashed;
    }

    private function restoredActionFor(User|Subscription|SubscriptionInvoice|SubscriptionPayment $record): AuditAction
    {
        return match (true) {
            $record instanceof User => AuditAction::UserRestored,
            $record instanceof Subscription => AuditAction::SubscriptionRestored,
            $record instanceof SubscriptionInvoice => AuditAction::SubscriptionInvoiceRestored,
            $record instanceof SubscriptionPayment => AuditAction::SubscriptionPaymentRestored,
        };
    }

    private function ensureTrashed(User|Subscription|SubscriptionInvoice|SubscriptionPayment $record): void
    {
        if (! $record->trashed()) {
            $this->refuse(__(':record is not deleted.', ['record' => $this->labelFor($record)]));
        }
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['restore' => $message]);
    }
}
