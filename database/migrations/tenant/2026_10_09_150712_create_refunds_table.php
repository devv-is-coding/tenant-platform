<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New table, and the fix for defect 9. The legacy modelled a refund as eight
     * columns on the invoice itself, plus three more on `payments`. That shape
     * permits exactly one refund per booking, ever - two successive partial
     * refunds were unrepresentable.
     *
     * The negotiation is ported faithfully: the customer requests with a reason,
     * a manager approves full or partial - or rejects with a reason - and only
     * then is it processed against the gateway. Approving is what returns the
     * inventory, by hard-deleting the booking's allocation rows so the freed
     * unit index becomes immediately rebookable.
     *
     * A refund can never exceed the booking's paid amount less prior refunds.
     * That is a condition across rows, so it is a service-layer invariant backed
     * by a randomised test rather than a constraint.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Which payment this refund reverses. Nullable for a refund settled
            // outside the original instrument.
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('type');
            $table->unsignedTinyInteger('status')->default(1);

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            // The customer's stated reason for the request.
            $table->text('reason')->nullable();

            // Central users; not real foreign keys.
            $table->foreignId('requested_by')->nullable()->index();
            $table->timestamp('requested_on')->nullable();

            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_on')->nullable();

            // Mandatory when the decision is a rejection.
            $table->text('decision_reason')->nullable();

            $table->timestamp('processed_on')->nullable();
            $table->string('stripe_refund_id')->nullable();

            $table->timestamp('created_on')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->softDeletes('deleted_on');

            $table->index(['booking_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
