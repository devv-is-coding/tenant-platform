<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy `invoices` table, renamed. This row is a *booking*; an invoice
     * is a billing document, and `payments` already models the money. That
     * misnomer is much of what made the legacy's two status axes look confusing
     * when they were in fact deliberate.
     *
     * Three orthogonal axes, and they must stay orthogonal:
     *
     *   `approval_status`   - the manager's decision
     *   `payment_status`    - the money state, always derived, never assigned
     *   `fulfilment_status` - whether the guest turned up (new)
     *
     * The proof the first two were meant to be independent is in the legacy
     * data: a rejected booking keeps its payment status at Unpaid. Preserved.
     *
     * `items.id` and `options.id` are gone from this row. The legacy carried a
     * single `option_id` plus UNIQUE(invoice_id, option_id), so booking two
     * rooms was unrepresentable - defect 4, fixed by `booking_lines`.
     *
     * The eight refund columns the legacy carried here are gone too; they
     * permitted exactly one refund per booking, ever. Defect 9.
     *
     * `amount_paid_minor` and `amount_refunded_minor` are not a second source of
     * truth: both are recomputed as a SUM inside the same transaction that
     * writes a payment or refund, and never incremented - incrementing is how
     * balances drift.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Human-quotable reference, from the legacy `code`.
            $table->string('reference', 32)->unique();

            // Customers are central users with a null tenant_id, so this cannot
            // be a real foreign key - the row lives in another database.
            // Mirrors sessions.user_id.
            $table->foreignId('customer_id')->index();

            // Snapshots, because users.tenant_id cascade-deletes and a booking
            // must stay legible after the customer record is gone.
            $table->string('customer_name');
            $table->string('customer_email');

            $table->unsignedTinyInteger('approval_status')->default(0);
            $table->unsignedTinyInteger('payment_status')->default(1);
            $table->unsignedTinyInteger('fulfilment_status')->default(1);

            $table->string('rejection_reason')->nullable();

            // Snapshotted from tenants.data. Not a competing source of truth: it
            // records what was charged, which must not change if the agency
            // later switches currency.
            $table->char('currency', 3);

            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->unsignedBigInteger('amount_refunded_minor')->default(0);

            $table->date('due_on')->nullable();

            // Inventory is allocated at booking creation, not at payment - the
            // legacy allocated at payment, so two customers could both hold
            // unpaid bookings for the last room. This is the hold's expiry.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Central users; not real foreign keys.
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->index();

            $table->string('stripe_invoice_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_invoice_url', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The two queues staff work from.
            $table->index(['approval_status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
