<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ported from the legacy `tour_visits` - a customer checking in at an
     * itinerary stop - and extended to cover the second scan direction.
     *
     * Two flows share this table:
     *
     *   Tour  - the guest scans a stop's QR, so `itinerary_stop_id` is set.
     *           Because geofencing is out of scope the QR is the sole proof of
     *           presence, so the stop's code is a short-lived rotating token
     *           rendered on the guide's device, not a static printed sheet that
     *           could be photographed and shared.
     *   Hotel - staff scan the guest's booking QR, so `itinerary_stop_id` is
     *           NULL and `recorded_by` is the staff member. Cross-tenant use is
     *           already blocked by the tenant middleware on the scanning session.
     *
     * UNIQUE(customer_id, itinerary_stop_id) prevents a double check-in at one
     * stop. Note the honest limits: for the hotel flow the stop is NULL, and
     * repeated NULLs do not collide on either driver, so "one check-in per
     * booking" there is a service-layer rule. And a customer who books the same
     * tour twice can only check in at a given stop once - the key should be
     * UNIQUE(booking_id, itinerary_stop_id), which is an approved fix pending
     * the next rebuild.
     *
     * `auto_detected` is deliberately dropped. Without geofencing nothing would
     * ever write it, and a column no producer sets is worse than no column.
     */
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // NULL for the hotel booking-level scan. Nulled rather than cascaded
            // on delete so check-in history survives a reordered itinerary.
            $table->foreignId('itinerary_stop_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot, because the stop it refers to may later be deleted.
            $table->string('stop_label')->nullable();

            // A central user, so not a real foreign key.
            $table->foreignId('customer_id')->index();

            $table->timestamp('checked_in_at');

            // The staff member who scanned, for the hotel direction.
            $table->foreignId('recorded_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'itinerary_stop_id']);
            $table->index(['booking_id', 'checked_in_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
