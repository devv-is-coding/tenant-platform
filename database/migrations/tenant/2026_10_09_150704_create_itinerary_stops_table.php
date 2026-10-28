<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ordered route of a tour, ported from the legacy `details` table.
     *
     * `sequence` makes the order explicit rather than implicit in insertion
     * order, and drives the numbered markers and polyline on the map.
     *
     * The coordinates are finally used: they existed in the legacy and every
     * row was NULL, so no map was ever drawn. Defect 11.
     *
     * Soft-deleted like every project table (CLAUDE.md), so a deleted stop
     * keeps its slot in UNIQUE(item_id, sequence): a new stop takes the next
     * sequence after every stop the tour has had, deleted ones included
     * (ItineraryStop::nextSequenceFor()). Check-in history survives a deleted
     * stop through its own snapshot.
     */
    public function up(): void
    {
        Schema::create('itinerary_stops', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('location');

            // ISO 3166-1 alpha-2. The legacy allowed 10 characters and left
            // every row NULL.
            $table->string('country_code', 2)->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Decimal, not float: coordinates need exact storage. Nullable
            // because a stop without them is omitted from the map, never
            // plotted at (0,0). Validated to +/-90 and +/-180 on write.
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['item_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_stops');
    }
};
