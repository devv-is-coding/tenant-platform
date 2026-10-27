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
     * Currency is deliberately absent. It lives only in `tenants.data`, which
     * onboarding writes on the central domain before this database exists,
     * and which tenancy loads with the tenant on every request. Two sources
     * of truth for money is how this goes wrong, so this table holds only
     * what the central side never sets.
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

            // The agency's branded app, served to it at runtime by
            // GET /api/v1/app-config. NULL means the platform default.
            $table->string('app_display_name', 40)->nullable();
            $table->char('brand_primary_color', 7)->nullable();
            $table->char('brand_accent_color', 7)->nullable();

            // A path on the agency's own `public` disk, which tenancy roots
            // in its own storage folder.
            $table->string('logo_path')->nullable();

            $table->string('support_email')->nullable();
            $table->string('support_phone', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();
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
