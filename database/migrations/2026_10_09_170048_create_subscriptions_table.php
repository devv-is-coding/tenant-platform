<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's commercial relationship with one agency: what it pays, how
     * often, and whether that arrangement is currently live.
     *
     * Central, not tenant-side, and not as a style choice. The super admin has
     * to answer "which agencies are due or overdue?" in one query; held in
     * tenant databases it would be a fan-out over every agency on the platform.
     * It is also a different money flow from the tenant-side `payments` table:
     * that one is customers paying agencies, this one is agencies paying the
     * platform. Different parties, different currency, different lifecycle.
     *
     * `status` is deliberately independent of `tenants.status`. A subscription
     * may be PastDue while the agency is still Active and trading, because an
     * unpaid invoice is a fact and suspending access is a separate decision.
     * Collapsing the two would mean a missed bank transfer takes a storefront
     * down the morning it falls due.
     *
     * The plan is held as snapshots, not read through `subscription_plan_id`.
     * These columns record what the agency actually agreed to, so a later
     * change to the plan in the price list never reprices a live subscription.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id')->index();

            // Holds the tenant id while this subscription is live, NULL once it
            // is cancelled or expired. Repeated NULLs do not collide in a unique
            // index on either driver, so this permits a full history of past
            // subscriptions per tenant but only one live one.
            $table->string('active_for_tenant_id')->nullable()->unique();

            // The price-list plan this was started from. Bare column with no
            // foreign key: subscription_plans is created after this table, and
            // the snapshot columns below, not the plan, are the agreed terms.
            $table->foreignId('subscription_plan_id')->nullable()->index();

            $table->string('plan_name');
            $table->unsignedBigInteger('amount_minor');

            // The PLATFORM's billing currency, from config - not the agency's
            // trading currency in tenants.data. Snapshotted so historical
            // records stay correct if the platform ever changes currency.
            $table->char('currency', 3);

            $table->unsignedTinyInteger('interval');
            $table->unsignedTinyInteger('status');

            $table->date('starts_on');
            $table->date('current_period_start_on');
            $table->date('current_period_end_on');

            // Drives the "due soon" console query.
            $table->date('next_billing_on')->nullable()->index();

            $table->date('trial_ends_on')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            // When access actually lapses, which may be after cancellation - a
            // cancelled subscription is usually honoured to the end of its paid
            // period.
            $table->date('ends_on')->nullable();

            // The super admin who set this up. Bare column with no foreign key,
            // following the same rule as every other actor column here: a
            // commercial record must outlive the operator who created it.
            $table->foreignId('created_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_billing_on']);

            // RESTRICT, not CASCADE: billing history must not be destroyable by
            // removing a tenant row. Closure is a status change, and a purge
            // after the retention window is a deliberate, ordered operation.
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('active_for_tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
