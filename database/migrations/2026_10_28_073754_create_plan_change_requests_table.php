<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An agency admin's request to be put on a plan from the platform's price
     * list, and the super admin's decision on it.
     *
     * An agency cannot pick its own plan: it asks, and a super admin approves
     * (which starts the subscription) or declines with a reason. The admin can
     * withdraw a request while it waits.
     *
     * Central, because plans, subscriptions and the super admin's list of every
     * agency's requests are all central.
     */
    public function up(): void
    {
        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id')->index();

            // Set to tenant_id while the request is pending and NULL once it is
            // decided or withdrawn, so the unique key allows one pending
            // request per agency and any number of closed ones - the same slot
            // subscriptions use for the one live plan (active_for_tenant_id).
            $table->string('pending_for_tenant_id')->nullable()->unique();

            // The plan asked for. RESTRICT: withdrawing a plan soft-deletes it,
            // so the row stays and the request can say what was asked for.
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();

            // Bare actor columns with no foreign key, like every other actor
            // column: the request must outlive the people on either side.
            $table->foreignId('requested_by')->nullable()->index();
            $table->text('reason')->nullable();

            $table->unsignedTinyInteger('status')->default(1)->index();

            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

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
        Schema::dropIfExists('plan_change_requests');
    }
};
