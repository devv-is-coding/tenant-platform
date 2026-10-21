<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tenant, holding the agency's operational defaults.
     *
     * Typed columns rather than key/value pairs, so PHPStan can see them and so
     * each setting gets a real type and default instead of a string parsed at
     * every read.
     *
     * Currency is deliberately absent. It lives only in `tenants.data`, because
     * the central discovery projection renders prices on the central domain
     * where tenancy is never initialized - a tenant-DB-only currency would be
     * unreadable there. Two sources of truth for money is how this goes wrong,
     * so this table holds only what the central domain never needs.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // Unique with a fixed default, so a second settings row is
            // impossible at the schema level rather than by convention.
            $table->unsignedTinyInteger('singleton')->default(1)->unique();

            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('locale', 10)->default('en');

            // How long an unpaid booking holds its inventory before the release
            // command frees it.
            $table->unsignedSmallInteger('booking_hold_minutes')->default(1440);

            // Percentage of the total accepted as a deposit, 0-100.
            $table->unsignedTinyInteger('deposit_percentage')->default(50);

            $table->unsignedSmallInteger('cancellation_window_hours')->default(48);

            // Real time columns, seeding the hotel profiles. The legacy stored
            // check-in as the text "2:00 PM", which is defect 6.
            $table->time('default_check_in_time')->default('14:00:00');
            $table->time('default_check_out_time')->default('12:00:00');

            // When false, staff-created listings publish without a manager's
            // approval.
            $table->boolean('requires_listing_approval')->default(true);

            $table->timestamp('created_on')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->softDeletes('deleted_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
