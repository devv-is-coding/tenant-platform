<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money an agency has actually paid the platform. The source of truth for
     * `subscription_invoices.amount_paid_minor`, which is recomputed from this
     * table as a SUM and never incremented.
     *
     * Many payments per invoice, because part-payment of a platform fee is as
     * real as a deposit on a booking.
     *
     * The shape mirrors the tenant-side `payments` table deliberately, including
     * `paid_on` as a real timestamp rather than the legacy's `int(11)` unix
     * integer (defect 10), and the unique `idempotency_key` so a replayed
     * gateway webhook cannot credit an invoice twice.
     *
     * Reuses `PaymentMethod` and `PaymentRecordStatus` rather than duplicating
     * them: cash, card, Stripe and bank transfer mean the same thing whoever is
     * paying whom.
     */
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            // RESTRICT: removing an invoice must not silently destroy the record
            // of money received against it.
            $table->foreignId('subscription_invoice_id')->constrained()->restrictOnDelete();

            // Denormalised for the same reason as on the invoice - see that
            // migration's note about the composite-key upgrade.
            $table->string('tenant_id')->index();

            $table->string('reference', 64)->unique();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            $table->unsignedTinyInteger('method');
            $table->unsignedTinyInteger('status');

            $table->timestamp('paid_on')->nullable();

            // The super admin who recorded a manual payment. A bare column with
            // no foreign key, and NULL for gateway-settled payments.
            $table->foreignId('recorded_by')->nullable()->index();

            // Nullable because a bank transfer recorded by hand has no key, and
            // repeated NULLs do not collide on either driver.
            $table->string('idempotency_key', 128)->nullable()->unique();

            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_charge_id')->nullable();

            $table->string('failure_reason')->nullable();

            $table->timestamp('created_on')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->softDeletes('deleted_on');

            // The SUM that recomputes the invoice balance.
            $table->index(['subscription_invoice_id', 'status']);

            // One agency's payment history, newest first.
            $table->index(['tenant_id', 'paid_on']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
