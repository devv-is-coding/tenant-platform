<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\BillSubscriptionAction;
use App\Actions\Subscriptions\SchedulePlanChangeAction;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('subscriptions:issue-invoices')]
#[Description('Issue the invoices billable subscriptions are due and mark overdue ones past due')]
class IssueSubscriptionInvoices extends Command
{
    /**
     * Execute the console command.
     *
     * Scheduled daily rather than monthly: every subscription bills on its own
     * anniversary, and issuing is idempotent, so a daily run catches each
     * period's first day without double-billing.
     *
     * A plan change the agency's Admin scheduled for the period's end is made
     * first, so the new period is billed on the new plan (TA-27).
     */
    public function handle(BillSubscriptionAction $billSubscription, SchedulePlanChangeAction $schedulePlanChange): int
    {
        $today = today();
        $issued = 0;
        $markedPastDue = 0;

        $billable = array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status): bool => $status->isBillable());

        Subscription::query()
            ->whereIn('status', $billable)
            ->chunkById(100, function (Collection $subscriptions) use ($billSubscription, $schedulePlanChange, $today, &$issued, &$markedPastDue): void {
                foreach ($subscriptions as $subscription) {
                    $result = $billSubscription->handle($schedulePlanChange->applyIfDue($subscription, $today) ?? $subscription, $today);

                    $issued += count($result['invoices']);
                    $markedPastDue += (int) $result['marked_past_due'];
                }
            });

        $this->components->info("Issued {$issued} invoice(s); marked {$markedPastDue} subscription(s) past due.");

        return self::SUCCESS;
    }
}
