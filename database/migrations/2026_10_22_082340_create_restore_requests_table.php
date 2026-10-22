<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An agency admin's request to have one of the agency's deleted records
     * brought back, and the super admin's decision on it.
     *
     * Only a super admin restores. Staff who delete something by mistake tell
     * their agency admin, the admin files one of these, and the super admin
     * restores or declines it.
     *
     * Central, because the records it points at - agency users and billing
     * rows - are central, and because the super admin answers every agency's
     * requests from one list.
     */
    public function up(): void
    {
        Schema::create('restore_requests', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id')->index();

            // The deleted record, under its morph alias.
            $table->string('restorable_type');
            $table->unsignedBigInteger('restorable_id');

            // Bare actor columns with no foreign key, like every other actor
            // column: the request must outlive the people on either side.
            $table->foreignId('requested_by')->nullable()->index();
            $table->text('reason');

            $table->unsignedTinyInteger('status')->default(1)->index();

            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['restorable_type', 'restorable_id']);

            // RESTRICT, like billing history: a request is part of the agency's
            // record and must not vanish with a tenant row.
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restore_requests');
    }
};
