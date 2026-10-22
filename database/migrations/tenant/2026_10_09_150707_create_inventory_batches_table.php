<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The additions half of the inventory ledger, ported from the legacy
     * `option_batches`. Availability was never a stored counter in the capstone,
     * and that design is kept: available = SUM(batches) - COUNT(allocations).
     * There is no `capacity_per_night` column anywhere, because a second source
     * of capacity would double-count.
     *
     * `effective_from`/`effective_to` are new, and make "three extra rooms,
     * December only" expressible - impossible in the legacy, where a batch
     * applied forever.
     *
     * `added_by` is nullable here, where the legacy had it NOT NULL and then
     * stored `added_by = 1` pointing at a user that does not exist, against a
     * users table whose ids start at 13. Because the actor is a central user in
     * another database, no foreign key can enforce this; the fix is validation
     * in the service layer, with a test named after defect 8.
     */
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('option_id')->constrained()->cascadeOnDelete();

            // Signed: corrections are append-only negative adjustments rather
            // than edits or deletions, so the ledger stays an auditable history.
            $table->integer('quantity');

            $table->unsignedTinyInteger('reason');
            $table->string('notes')->nullable();

            // NULL means the batch applies without a date bound.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->foreignId('added_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['option_id', 'effective_from', 'effective_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
