<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed attributes for a Hotel item, replacing the legacy EAV rows named
     * "Adults", "Amenities", "Check-in Time" and "Check-out Time".
     *
     * Check-in and check-out are real `time` columns, seeded from the tenant's
     * settings defaults. In the legacy they were EAV text, and the dataset's
     * hotel check-in time is the literal string "2:00 PM" - unusable for any
     * comparison. That is the most concrete illustration of defect 6.
     */
    public function up(): void
    {
        Schema::create('hotel_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('max_adults');
            $table->json('amenities');

            $table->time('check_in_time');
            $table->time('check_out_time');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_profiles');
    }
};
