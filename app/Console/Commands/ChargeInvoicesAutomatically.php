<?php

namespace App\Console\Commands;

use App\Actions\Subscriptions\ChargeInvoiceAutomaticallyAction;
use App\Enums\SubscriptionInvoiceStatus;
use App\Models\SubscriptionInvoice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('subscriptions:auto-charge')]
#[Description('Charge agencies\' saved cards for the invoices auto-pay is due to pay')]
class ChargeInvoicesAutomatically extends Command
{
    /**
     * Execute the console command.
     *
     * Runs daily after `subscriptions:issue-invoices`, so a new invoice is
     * charged the day it is issued (TA-33). Only invoices still owed, of an
     * agency with auto-pay on, with an attempt left, are looked at; whether
     * one is due is ChargeInvoiceAutomaticallyAction's to decide.
     */
    public function handle(ChargeInvoiceAutomaticallyAction $charge): int
    {
        $today = today();
        $outcomes = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];

        SubscriptionInvoice::query()
            ->whereIn('status', [SubscriptionInvoiceStatus::Open, SubscriptionInvoiceStatus::PartiallyPaid])
            ->where('auto_charge_attempts', '<', ChargeInvoiceAutomaticallyAction::MAX_ATTEMPTS)
            ->whereHas('tenant', fn (Builder $query) => $query->whereNotNull('auto_pay_since')->whereNotNull('stripe_payment_method_id'))
            ->chunkById(100, function (Collection $invoices) use ($charge, $today, &$outcomes): void {
                foreach ($invoices as $invoice) {
                    $outcomes[$charge->handle($invoice, $today)]++;
                }
            });

        $this->components->info("Charged {$outcomes['paid']} invoice(s); {$outcomes['failed']} failed, {$outcomes['pending']} still processing.");

        return self::SUCCESS;
    }
}
