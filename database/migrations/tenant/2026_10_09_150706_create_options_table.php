<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bookable unit, and the most important structural idea carried over
     * from the capstone. An option is a priced, countable variant of an item -
     * Solo/Couple/Family for a tour, Room 101 for a hotel, e-Visa for a visa.
     * Keeping it generic across all three types is what lets one booking engine
     * serve everything.
     *
     * Three changes from the legacy `options` table:
     *
     * `price` was `double(10,2)`. Money is now an integer count of minor units
     * in `price_minor`, which an architecture test enforces across the whole
     * schema. Defect 2.
     *
     * `available_quantity` is gone. It duplicated the inventory ledger and was
     * the value that drifted, so availability is always derived from batches
     * minus allocations and never stored.
     *
     * `availability_mode` is new and lives here rather than on `items`, so
     * options stay genuinely generic - one hotel can sell both a per-night room
     * and a fixed-date New Year's package.
     *
     * There is no `currency` column: a tenant trades in exactly one currency,
     * held in `tenants.data` where the central marketplace can read it without
     * initialising tenancy.
     */
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedTinyInteger('availability_mode');
            $table->unsignedBigInteger('price_minor');

            // Nightly mode only; NULL for Capacity and Unlimited.
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->unsignedSmallInteger('max_nights')->nullable();

            $table->boolean('is_active')->default(true);

            // Central users; not real foreign keys. See items.created_by.
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('deleted_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'availability_mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
