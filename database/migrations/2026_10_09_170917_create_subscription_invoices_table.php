<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per billing period: what the platform charged an agency, when it
     * fell due, and how much of it has been settled.
     *
     * The shape deliberately mirrors `bookings` - an amount, a running
     * `amount_paid_minor`, and a status derived from the two - because that is
     * the shape already proven to keep a balance honest. `amount_paid_minor` is
     * recomputed as a SUM over `subscription_payments` inside the same
     * transaction that writes a payment, and never incremented.
     *
     * There is no `overdue` column. Overdue is `status is not Paid AND due_on <
     * today` - a function of the clock, which has no business being stored. A
     * flag would need a nightly job to flip it and would be silently wrong the
     * first time that job failed.
     *
     * Voiding rather than deleting is how a charge is withdrawn, so the record
     * of having issued it survives. There is deliberately no refund table for
     * platform fees: voiding covers the realistic case.
     */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a financial record must not vanish because the
            // subscription it belongs to was removed.
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();

            // Denormalised from the subscription so the console can filter one
            // agency's invoices without a join. It cannot drift because a
            // subscription is never reassigned to another tenant - an invariant
            // the service must hold and a test must assert, since nothing here
            // enforces it. Adding UNIQUE(id, tenant_id) to `subscriptions` would
            // let a composite foreign key guarantee it outright; that needs an
            // edit to the subscriptions migration, so it is folded into the next
            // rebuild.
            $table->string('tenant_id')->index();

            $table->string('reference', 32)->unique();

            $table->date('period_start_on');
            $table->date('period_end_on');

            $table->date('issued_on');
            $table->date('due_on')->index();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_paid_minor')->default(0);

            // Defaults to Open (1). Derived from the amounts, never assigned.
            $table->unsignedTinyInteger('status')->default(1);

            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Issuing a period twice must produce one invoice, so the monthly
            // billing job is idempotent by construction rather than by
            // remembering to check first.
            $table->unique(['subscription_id', 'period_start_on']);

            // One agency's unpaid invoices, and the platform-wide overdue list.
            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['status', 'due_on']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
