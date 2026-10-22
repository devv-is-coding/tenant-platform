<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New table, and the structural fix for defect 4. The legacy put a single
     * `option_id` on the invoice and added UNIQUE(invoice_id, option_id) on top,
     * so two rooms - or a tour plus a visa - could not be expressed at all.
     *
     * The honest limit: a booking row lives in one tenant database, so a tour
     * from agency A plus a visa from agency B is necessarily two bookings. An
     * unavoidable consequence of multi-database tenancy. A central cart may
     * group them for checkout, but the records stay separate.
     *
     * The snapshot columns are as much the point as the one-to-many is:
     * `item_name`, `option_name`, `item_type`, `availability_mode` and
     * `unit_price_minor` are copied at purchase, so a 2024 booking still renders
     * correctly after the catalog is renamed and repriced.
     *
     * `adults`, `kids` and `baggage_count` are the legacy `invoice_attributes`
     * EAV values promoted to real columns.
     *
     * Per-mode date semantics, which are easy to get wrong:
     *
     *   Capacity (tour)  - copied from the item's departure window, INCLUSIVE of
     *                      both ends. `nights` is NULL.
     *   Nightly (hotel)  - the guest's range, HALF-OPEN [starts_on, ends_on).
     *                      The check-out day is not a consumed night, which is
     *                      what makes back-to-back bookings work.
     *   Unlimited (visa) - both NULL, and no allocation rows at all.
     *
     * Never compare these dates by hand; always route through the date-range
     * value object, because mixing the conventions is the most common
     * hotel-availability bug there is.
     */
    public function up(): void
    {
        Schema::create('booking_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Real foreign keys: options and items live in this same tenant
            // database, which is what makes referencing another tenant's option
            // structurally impossible. Restricted rather than cascading, so
            // purging a catalog row cannot quietly destroy sold history.
            $table->foreignId('option_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();

            $table->string('item_name');
            $table->string('option_name');
            $table->unsignedTinyInteger('item_type');
            $table->unsignedTinyInteger('availability_mode');
            $table->unsignedBigInteger('unit_price_minor');

            // Units booked - two rooms, three seats.
            $table->unsignedSmallInteger('quantity')->default(1);

            // Nightly only. Equals starts_on->diffInDays(ends_on).
            $table->unsignedSmallInteger('nights')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->unsignedBigInteger('line_total_minor');

            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('kids')->default(0);
            $table->unsignedSmallInteger('baggage_count')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['option_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_lines');
    }
};
