<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed attributes for a Tour item, replacing the legacy EAV rows named
     * "Travel Dates", "Adults", "Kids", "Baggage", "Inclusions", "Itinerary"
     * and "Meeting Point".
     *
     * A per-type satellite rather than one wide `items` table or a JSON blob: a
     * wide table would force every type-specific column nullable, destroying the
     * validation being bought, and a blob would lose queryability too. Here the
     * constraints are real and visible to PHPStan.
     *
     * `departs_on`/`returns_on` are the departure window, inclusive of both
     * ends, which Capacity-mode options consume seats for. The legacy held this
     * as EAV text, which is how one row ended up with the end year 0026 and
     * another ended before it started. Defect 3.
     *
     * Free-form lists stay JSON behind a cast that validates every element is a
     * string - validation at the model boundary, which the EAV never had.
     */
    public function up(): void
    {
        Schema::create('tour_profiles', function (Blueprint $table) {
            $table->id();

            // Unique is what makes this a satellite rather than a collection.
            // It does not stop a Hotel item being given a tour profile; the
            // model refuses that (BelongsToItemOfType).
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('max_adults');
            $table->unsignedSmallInteger('max_kids')->default(0);
            $table->unsignedSmallInteger('baggage_allowance_kg')->nullable();

            $table->string('meeting_point');
            $table->json('inclusions');

            $table->date('departs_on');
            $table->date('returns_on');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_profiles');
    }
};
