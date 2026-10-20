<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\BillSubscriptionAction;
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
     */
    public function handle(BillSubscriptionAction $billSubscription): int
    {
        $today = today();
        $issued = 0;
        $markedPastDue = 0;

        $billable = array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status): bool => $status->isBillable());

        Subscription::query()
            ->whereIn('status', $billable)
            ->chunkById(100, function (Collection $subscriptions) use ($billSubscription, $today, &$issued, &$markedPastDue): void {
                foreach ($subscriptions as $subscription) {
                    $result = $billSubscription->handle($subscription, $today);

                    $issued += count($result['invoices']);
                    $markedPastDue += (int) $result['marked_past_due'];
                }
            });

        $this->components->info("Issued {$issued} invoice(s); marked {$markedPastDue} subscription(s) past due.");

        return self::SUCCESS;
    }
}
