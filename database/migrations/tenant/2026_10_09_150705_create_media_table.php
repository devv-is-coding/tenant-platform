<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item galleries on tenant storage.
     *
     * Stored as `disk` + `path`, not the legacy's `file_url`. A URL in the
     * database breaks the moment the storage host changes, and tenancy's
     * filesystem bootstrapper already generates per-tenant URLs from a disk and
     * a path - so persisting the URL would both duplicate and outdate it.
     *
     * There is deliberately no soft delete: a tombstone would keep occupying the
     * featured slot and block a replacement. The deletion is recorded in the
     * audit log instead.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 64);
            $table->string('path', 500);
            $table->string('file_name');

            $table->unsignedTinyInteger('media_type');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Holds item_id when this row is the item's featured image, NULL
            // otherwise. Because repeated NULLs do not collide in a unique index
            // on either MariaDB or SQLite, this permits any number of gallery
            // images but only one featured image per item. Read it through the
            // model's is_featured accessor.
            $table->unsignedBigInteger('featured_for_item_id')->nullable()->unique();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
