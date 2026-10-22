<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalog. One polymorphic table carries all three product types via
     * `item_type`, as the legacy did - that is what lets one booking engine
     * serve tours, visas and hotels alike.
     *
     * The legacy exposed per-type fields through an untyped EAV trio
     * (`attributes`, `item_attributes`, `invoice_attributes`). Those are dropped
     * in favour of the typed 1:1 satellites `tour_profiles`, `visa_profiles` and
     * `hotel_profiles`, which carry real NOT NULL constraints per type. Defect 6.
     *
     * `item_status` runs Draft(0) -> Pending(1) -> Approved(2) -> Published(4),
     * with Rejected(3) returning the listing to an editable state. Only
     * Published is visible to customers.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('item_type');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->unsignedTinyInteger('item_status')->default(0);
            $table->string('rejection_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // Staff and managers are central users, so none of these can be real
            // foreign keys - the row lives in another database. Mirrors
            // sessions.user_id. Validated in the service layer instead.
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('edited_by')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->index();
            $table->foreignId('deleted_by')->nullable()->index();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_status', 'item_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
