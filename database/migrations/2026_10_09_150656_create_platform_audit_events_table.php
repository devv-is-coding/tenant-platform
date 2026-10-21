<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail for platform-level actions, in the central
     * database. Same shape as the tenant-side `audit_events`, plus the tenant
     * the action concerned.
     *
     * Rows land here when the *subject* is platform-level - a tenant being
     * suspended, a subscription payment, a failed login against the central
     * console - rather than when the actor happens to be a super admin.
     *
     * A single central table for all auditing was rejected: the hottest audit
     * writers are the tenant-local booking and payment flows, and routing those
     * centrally would make the central database the write bottleneck for the
     * whole platform.
     */
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->id();

            // Nullable, and SET NULL rather than CASCADE on delete: deleting a
            // tenant must not erase the record of what was done to it. The label
            // is what keeps the row legible afterwards.
            $table->string('tenant_id')->nullable()->index();
            $table->string('tenant_label')->nullable();

            // Central users live in this database, but this is still not a
            // foreign key: users.tenant_id cascade-deletes, and history must
            // outlive the user it describes. NULL, never 0, when unauthenticated.
            $table->foreignId('actor_id')->nullable()->index();

            // Snapshots, not joins - see audit_events.
            $table->unsignedTinyInteger('actor_role')->nullable();
            $table->string('actor_label')->nullable();

            $table->string('action', 64)->index();

            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->text('description')->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_on')->nullable()->index();
            $table->softDeletes('deleted_on');

            $table->index(['subject_type', 'subject_id']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
