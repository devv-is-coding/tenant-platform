<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Booking-gated reviews, ported from the legacy `feedbacks` table.
     *
     * UNIQUE(customer_id, booking_id) carries the gate the legacy already had:
     * one review per booking, and only from the customer who made it. Whether
     * they may write one at all - a paid, fulfilled booking - is authorisation
     * rather than schema.
     *
     * `is_approved` defaults to true, faithful to the legacy, with moderation
     * available after the fact rather than before.
     *
     * Approving or deleting a review recomputes the rating aggregate on the
     * central `listings` projection. The delete side is the easy half to forget.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            // A central user, so not a real foreign key.
            $table->foreignId('customer_id')->index();

            // Snapshot, so an existing review still renders a name after the
            // customer record is gone.
            $table->string('customer_name');

            $table->string('title', 100)->nullable();
            $table->text('content')->nullable();

            // 1 to 5, validated on write.
            $table->unsignedTinyInteger('rating');

            // Disk-relative paths, for the same reason as the media table: never
            // store a URL, because it breaks when the storage host changes.
            $table->json('image_paths')->nullable();

            $table->boolean('is_approved')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'booking_id']);
            $table->index(['item_id', 'is_approved', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
