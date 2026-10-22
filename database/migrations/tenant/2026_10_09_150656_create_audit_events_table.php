<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail for everything that happens inside this tenant.
     *
     * This one shape replaces the legacy's two redundant tables (`logs` and
     * `histories`, which differed only in payload): `changes` carries the
     * old/new diff `histories` held, `context` the arbitrary metadata `logs`
     * held. Defect 5.
     *
     * Rows are routed by where the *subject* lives, not by who the actor is, so
     * an audit record is co-located with the data it describes and survives and
     * dies with it. Platform-level actions go to the central
     * `platform_audit_events` table instead.
     *
     * A super admin may write a platform lifecycle notice here - a suspension or
     * closure the agency is entitled to a permanent local record of - but never
     * an operational action. `actor_role = SuperAdmin` against anything other
     * than a `tenant.*` action is a governance violation.
     *
     * There is no `updated_at` and no soft delete: audit rows are never modified
     * or removed, which the model enforces in `booted()`. Retention is handled
     * by pruning old rows outright.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Actors are central users, so this cannot be a real foreign key -
            // the row lives in another database. Mirrors sessions.user_id.
            // Nullable for unauthenticated actions, and NULL rather than 0,
            // which is the legacy's `logs.user_id = 0` against a users table
            // whose ids start at 13.
            $table->foreignId('actor_id')->nullable()->index();

            // Snapshots, not joins. Roles get changed and users get deleted, and
            // the audit trail must say what was true at the time.
            $table->unsignedTinyInteger('actor_role')->nullable();
            $table->string('actor_label')->nullable();

            $table->string('action', 64)->index();

            // Morph-map alias, never an FQCN, so renaming a class does not
            // orphan its history. Nullable because a few actions have no
            // subject. Kept short to keep the composite index narrow.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->text('description')->nullable();

            // From the legacy `histories` and `logs.metadata_json` respectively.
            $table->json('changes')->nullable();
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->nullable()->index();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
