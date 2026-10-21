<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy `likes` table, renamed and moved central.
     *
     * A wishlist is inherently cross-tenant: a customer saves a tour from one
     * agency next to a hotel from another. Central means one query renders the
     * whole thing, where a per-tenant table would fan out over every tenant
     * database on every page load.
     *
     * It points at `listings` rather than a tenant item id, which is the other
     * reason the projection carries a ULID: a wishlist row needs a stable,
     * globally unique handle for something that lives in another database.
     */
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();

            // Both are central rows, so these are real foreign keys - unlike
            // every customer reference on the tenant side.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_on')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->softDeletes('deleted_on');

            $table->unique(['user_id', 'listing_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
