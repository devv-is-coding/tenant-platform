<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The consumptions half of the inventory ledger, ported from the legacy
     * `option_slots` - and the table that carries the concurrency guarantee.
     *
     * UNIQUE(option_id, occupied_on, unit_index) IS the guarantee. Not a
     * belt-and-braces index on top of a lock; the only thing that makes
     * overselling impossible, for a verified reason: Laravel's SQLite grammar
     * compiles `lockForUpdate()` to an empty string, and the test suite runs on
     * SQLite while the application runs on MariaDB. A row lock is therefore a
     * no-op in every test, and a test written to prove the lock works would be a
     * passing test for broken code. A unique index behaves identically on both
     * drivers. The row lock stays, but only as a UX optimisation that turns the
     * race into a wait rather than an error.
     *
     * One row per unit per occupied date. A two-room, five-night stay is ten
     * rows; a three-seat tour booking is three rows against the departure date.
     * This unit grain is what gives the index teeth: `unit_index` names which of
     * the option's N units is taken, so the Nth+1 attempt collides. A row
     * carrying a quantity instead would let two rows claim four units across two
     * index slots, and the guarantee would evaporate.
     *
     * `occupied_on` is NOT NULL, and that matters more than it looks. In both
     * MariaDB and SQLite, repeated NULLs in a unique index do not collide, so a
     * nullable date would silently void the whole guarantee. Capacity mode has
     * no per-night dimension, so it uses the departure start date.
     *
     * Release hard-deletes these rows, never soft-deletes them: a tombstone
     * would still occupy its slot in the unique index and block resale, and the
     * UNIQUE(..., deleted_at) trick fails for the same NULL reason. This
     * deliberately reverses the legacy, which soft-deleted slots on refund.
     * History lives in the audit log, not in tombstones.
     *
     * `batch_id` is deliberately absent. The invariant is the aggregate
     * SUM(batches) - COUNT(allocations); a half-maintained batch attribution is
     * worse than none, and strict FIFO burn-down is more bookkeeping than this
     * domain needs.
     */
    public function up(): void
    {
        Schema::create('inventory_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_line_id')->constrained()->cascadeOnDelete();

            $table->date('occupied_on');

            // Which unit of the option this row holds, assigned from the
            // currently free indices and reusable once released.
            $table->unsignedSmallInteger('unit_index');

            $table->timestamps();

            // The guarantee.
            $table->unique(['option_id', 'occupied_on', 'unit_index']);

            // Availability becomes one GROUP BY over this index, and overlap
            // detection an equality join rather than interval arithmetic.
            $table->index(['option_id', 'occupied_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_allocations');
    }
};
