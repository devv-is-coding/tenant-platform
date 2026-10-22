<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many payments per booking. Deposit-then-balance is a first-class flow, not
     * an edge case: the real sequence in the legacy data is staff recording cash
     * 400.00 against a 599.00 booking, the customer later settling the 199.00
     * balance through a hosted invoice link, and a webhook closing it out.
     *
     * Three fixes from the legacy `payments` table:
     *
     * `paid_at` was an `int(11)` unix timestamp; it is a real timestamp here.
     * Defect 10.
     *
     * `amount_paid` was `double(10,2)`; money is now integer minor units.
     * Defect 2.
     *
     * `idempotency_key` is new and unique, so a replayed webhook cannot
     * double-credit a booking.
     *
     * The legacy's three refund columns on this table have moved to `refunds`,
     * along with the eight that were on the booking.
     *
     * This table is the source of truth for how much has been paid.
     * `bookings.amount_paid_minor` is recomputed from it as a SUM inside the
     * same transaction, never incremented.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // From the legacy `ref_code`.
            $table->string('reference', 64)->unique();

            $table->unsignedBigInteger('amount_minor');

            // Snapshotted from the booking, so a historical payment still
            // formats correctly if the agency later changes currency.
            $table->char('currency', 3);

            $table->unsignedTinyInteger('method');
            $table->unsignedTinyInteger('status');

            $table->timestamp('paid_at')->nullable();

            // The staff member who recorded a cash payment. A central user, so
            // not a real foreign key, and NULL for gateway-settled payments.
            $table->foreignId('recorded_by')->nullable()->index();

            // Nullable because a cash payment has no gateway key, and repeated
            // NULLs do not collide in a unique index on either driver - which is
            // exactly the behaviour wanted here.
            $table->string('idempotency_key', 128)->nullable()->unique();

            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_charge_id')->nullable();

            $table->string('failure_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
