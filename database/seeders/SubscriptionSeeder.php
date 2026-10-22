<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\Role;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionSeeder extends Seeder
{
    /**
     * Seed the platform's billing relationship with every tenant: one
     * subscription each, a fully paid invoice from last period, and this
     * period's invoice in whatever state tells the more interesting story -
     * paid in full, still open, or genuinely overdue.
     *
     * Entirely central: this is the platform billing an agency, a different
     * money flow from the tenant-side `payments` table. Run TenantSeeder first.
     */
    public function run(): void
    {
        if (DB::table('subscriptions')->exists()) {
            return;
        }

        $superAdminId = User::query()->where('role', Role::SuperAdmin)->value('id');

        Tenant::query()->each(function (Tenant $tenant) use ($superAdminId): void {
            $this->seedSubscription($tenant, $superAdminId);
        });
    }

    private function seedSubscription(Tenant $tenant, ?int $superAdminId): void
    {
        $now = now();
        $startsOn = $now->copy()->subMonths(2)->startOfMonth();
        $amountMinor = $this->monthlyPriceFor($tenant->id);
        $currency = 'PHP';
        $interval = BillingInterval::Monthly;

        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'tenant_id' => $tenant->id,
            'active_for_tenant_id' => $tenant->id,
            'plan_name' => 'Agency Standard',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'interval' => $interval->value,
            'status' => SubscriptionStatus::Active->value,
            'starts_on' => $startsOn,
            'current_period_start_on' => $interval->advance($startsOn),
            'current_period_end_on' => $interval->advance($interval->advance($startsOn))->subDay(),
            'next_billing_on' => $interval->advance($interval->advance($startsOn)),
            'created_by' => $superAdminId,
            'created_at' => $startsOn,
            'updated_at' => $startsOn,
        ]);

        $this->platformAudit($tenant, $superAdminId, 'subscription.created', $subscriptionId, $startsOn);

        // Last period: issued and settled in full, mirroring a booking that
        // reaches Paid - amount_paid_minor is a SUM over subscription_payments,
        // never incremented.
        $lastPeriodStart = $startsOn;
        $lastPeriodEnd = $interval->advance($lastPeriodStart)->subDay();
        $depositMinor = intdiv($amountMinor * 2, 5);
        $this->seedInvoice(
            $tenant,
            $subscriptionId,
            $superAdminId,
            $amountMinor,
            sequence: 1,
            periodStart: $lastPeriodStart,
            periodEnd: $lastPeriodEnd,
            issuedOn: $lastPeriodStart,
            payments: [
                ['method' => PaymentMethod::BankTransfer, 'amount_minor' => $depositMinor, 'paid_at' => $lastPeriodStart->copy()->addDays(3)],
                ['method' => PaymentMethod::Stripe, 'amount_minor' => $amountMinor - $depositMinor, 'paid_at' => $lastPeriodStart->copy()->addDays(5)],
            ],
        );

        // This period: Dumaguete's invoice is still open and not yet due, the
        // others are genuinely overdue - a fact the console surfaces without
        // any status flip, since enforcement is a deliberate decision.
        $thisPeriodStart = $interval->advance($lastPeriodStart);
        $thisPeriodEnd = $interval->advance($thisPeriodStart)->subDay();
        $dueOn = $tenant->id === 'dumaguete' ? $now->copy()->addDays(10) : $now->copy()->subDays(14);

        $this->seedInvoice(
            $tenant,
            $subscriptionId,
            $superAdminId,
            $amountMinor,
            sequence: 2,
            periodStart: $thisPeriodStart,
            periodEnd: $thisPeriodEnd,
            issuedOn: $thisPeriodStart,
            payments: [],
            dueOn: $dueOn,
        );

        if ($tenant->id !== 'dumaguete') {
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => SubscriptionStatus::PastDue->value]);
        }
    }

    /**
     * @param  list<array{method: PaymentMethod, amount_minor: int, paid_at: CarbonInterface}>  $payments
     */
    private function seedInvoice(
        Tenant $tenant,
        int $subscriptionId,
        ?int $superAdminId,
        int $amountMinor,
        int $sequence,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        CarbonInterface $issuedOn,
        array $payments,
        ?CarbonInterface $dueOn = null,
    ): void {
        $currency = 'PHP';

        $invoiceId = DB::table('subscription_invoices')->insertGetId([
            'subscription_id' => $subscriptionId,
            'tenant_id' => $tenant->id,
            'reference' => sprintf('PLAT-%s-%s-%04d', $periodEnd->format('Y-m'), $this->tenantCode($tenant->id), $sequence),
            'period_start_on' => $periodStart,
            'period_end_on' => $periodEnd,
            'issued_on' => $issuedOn,
            'due_on' => $dueOn ?? $issuedOn->copy()->addDays(10),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'amount_paid_minor' => 0,
            'status' => SubscriptionInvoiceStatus::Open->value,
            'created_at' => $issuedOn,
            'updated_at' => $issuedOn,
        ]);

        $this->platformAudit($tenant, $superAdminId, 'subscription.invoice_issued', $invoiceId, $issuedOn);

        $amountPaid = 0;

        foreach ($payments as $index => $payment) {
            $amountPaid += $payment['amount_minor'];

            $paymentId = DB::table('subscription_payments')->insertGetId([
                'subscription_invoice_id' => $invoiceId,
                'tenant_id' => $tenant->id,
                'reference' => sprintf('PLATPAY-%s-%04d-%d', $this->tenantCode($tenant->id), $sequence, $index + 1),
                'amount_minor' => $payment['amount_minor'],
                'currency' => $currency,
                'method' => $payment['method']->value,
                'status' => PaymentRecordStatus::Succeeded->value,
                'paid_at' => $payment['paid_at'],
                'recorded_by' => $payment['method']->isRecordedByStaff() ? $superAdminId : null,
                'idempotency_key' => $payment['method']->isGatewaySettled() ? "evt_plat_{$invoiceId}_{$index}" : null,
                'created_at' => $payment['paid_at'],
                'updated_at' => $payment['paid_at'],
            ]);

            $this->platformAudit($tenant, $superAdminId, 'subscription.payment_recorded', $paymentId, $payment['paid_at']);

            DB::table('subscription_invoices')->where('id', $invoiceId)->update([
                'amount_paid_minor' => $amountPaid,
                'status' => SubscriptionInvoiceStatus::fromAmounts($amountMinor, $amountPaid)->value,
            ]);
        }
    }

    private function platformAudit(Tenant $tenant, ?int $actorId, string $action, int $subjectId, CarbonInterface $createdAt): void
    {
        DB::table('platform_audit_events')->insert([
            'tenant_id' => $tenant->id,
            'tenant_label' => $tenant->name,
            'actor_id' => $actorId,
            'actor_role' => Role::SuperAdmin->value,
            'action' => $action,
            'subject_type' => match ($action) {
                'subscription.invoice_issued' => 'subscription_invoice',
                'subscription.payment_recorded' => 'subscription_payment',
                default => 'subscription',
            },
            'subject_id' => $subjectId,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Get the agency's monthly price in minor units. It varies by locality:
     * the provincial capital's market is the largest, a municipality's the
     * smallest.
     */
    private function monthlyPriceFor(string $tenantId): int
    {
        return match ($tenantId) {
            'dumaguete' => 350000,
            'tanjay' => 250000,
            default => 180000,
        };
    }

    private function tenantCode(string $tenantId): string
    {
        return match ($tenantId) {
            'dumaguete' => 'DGTE',
            'tanjay' => 'TNJY',
            default => 'ZMB',
        };
    }
}
