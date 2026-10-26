<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy `likes` table, renamed: the items a customer has saved in
     * their agency's app.
     *
     * Per agency, because a customer belongs to one agency at a time and its
     * app shows only that agency's catalog, so the agency is the table's sole
     * reader and writer. Living next to `items` makes the item a real foreign
     * key.
     */
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();

            // A central user, so not a real foreign key.
            $table->foreignId('customer_id')->index();

            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // A soft-deleted row still holds this key, so saving an item again
            // restores the old row rather than inserting a new one.
            $table->unique(['customer_id', 'item_id']);
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
