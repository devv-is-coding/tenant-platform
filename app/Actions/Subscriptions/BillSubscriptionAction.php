<?php

namespace App\Actions\Subscriptions;

use App\Enums\AuditAction;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonInterface;

class BillSubscriptionAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Bring one subscription's billing up to date as of the given day.
     *
     * Billing is in advance, on the subscription's own anniversary: each
     * period's invoice is issued on the period's first day. A period whose
     * invoice already exists is skipped, so running this any number of times a
     * day issues each invoice once; the UNIQUE(subscription_id,
     * period_start_on) key backs that up. When the next billing date has
     * arrived the period rolls forward and its invoice follows, and a missed
     * run catches up one period at a time.
     *
     * Finally, an Active subscription with an overdue invoice is marked
     * PastDue. That records a commercial fact and nothing more: the agency's
     * own status is never touched, because suspending it is a decision a super
     * admin makes, not a side effect of an unpaid invoice.
     *
     * @return array{invoices: list<SubscriptionInvoice>, marked_past_due: bool}
     */
    public function handle(Subscription $subscription, CarbonInterface $today): array
    {
        $today = $today->copy()->startOfDay();

        return $subscription->getConnection()->transaction(function () use ($subscription, $today): array {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isBillable()) {
                return ['invoices' => [], 'marked_past_due' => false];
            }

            $invoices = [];

            while (true) {
                $invoice = $this->issueCurrentPeriod($locked, $today);

                if ($invoice !== null) {
                    $invoices[] = $invoice;
                }

                if ($locked->next_billing_on === null || $locked->next_billing_on->gt($today)) {
                    break;
                }

                $this->advancePeriod($locked);
            }

            return ['invoices' => $invoices, 'marked_past_due' => $this->markPastDueIfOverdue($locked, $today)];
        });
    }

    /**
     * Issue the invoice for the subscription's current period, unless it has
     * one already or the period falls inside a free trial.
     */
    private function issueCurrentPeriod(Subscription $subscription, CarbonInterface $today): ?SubscriptionInvoice
    {
        $periodStartOn = $subscription->current_period_start_on;

        if ($subscription->trial_ends_on !== null && $periodStartOn->lt($subscription->trial_ends_on)) {
            return null;
        }

        // A deleted invoice still counts: the period was billed and then
        // withdrawn on purpose, and its row still holds the unique key.
        if ($subscription->invoices()->withTrashed()->whereDate('period_start_on', $periodStartOn)->exists()) {
            return null;
        }

        $invoice = $subscription->invoices()->create([
            'tenant_id' => $subscription->tenant_id,
            'reference' => $this->nextReference($subscription->current_period_end_on),
            'period_start_on' => $periodStartOn,
            'period_end_on' => $subscription->current_period_end_on,
            'issued_on' => $today,
            'due_on' => $today->copy()->addDays((int) config('tenancy.billing_due_days')),
            'amount_minor' => $subscription->amount_minor,
            'currency' => $subscription->currency,
        ]);

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::SubscriptionInvoiceIssued,
            null,
            $subscription->tenant,
            subject: $invoice,
        );

        return $invoice;
    }

    /**
     * Move the subscription on to its next billing period.
     */
    private function advancePeriod(Subscription $subscription): void
    {
        /** @var CarbonInterface $periodStartOn */
        $periodStartOn = $subscription->next_billing_on;

        $subscription->update([
            'current_period_start_on' => $periodStartOn,
            'current_period_end_on' => $subscription->interval->advance($periodStartOn)->subDay(),
            'next_billing_on' => $subscription->interval->advance($periodStartOn),
        ]);
    }

    /**
     * Mark an Active subscription PastDue once any of its invoices is overdue.
     */
    private function markPastDueIfOverdue(Subscription $subscription, CarbonInterface $today): bool
    {
        if ($subscription->status !== SubscriptionStatus::Active
            || ! $subscription->invoices()->overdue($today)->exists()) {
            return false;
        }

        $subscription->update(['status' => SubscriptionStatus::PastDue]);

        $this->auditRecorder->recordPlatformEvent(
            AuditAction::SubscriptionMarkedPastDue,
            null,
            $subscription->tenant,
            changes: ['status' => ['old' => SubscriptionStatus::Active->value, 'new' => SubscriptionStatus::PastDue->value]],
            subject: $subscription,
        );

        return true;
    }

    /**
     * Number invoices within the month their period ends in, e.g.
     * PLAT-2026-11-0001 for a period ending in November 2026.
     *
     * Deleted invoices are counted too - they are only soft-deleted and keep
     * their references - so the count only grows and a number is never
     * reused. The unique reference column refuses any collision.
     */
    private function nextReference(CarbonInterface $periodEndOn): string
    {
        $prefix = 'PLAT-'.$periodEndOn->format('Y-m').'-';
        $sequence = SubscriptionInvoice::query()->withTrashed()->where('reference', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
