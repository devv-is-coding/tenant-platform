<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform telling an agency's Admin something about the agency's
     * standing with it: a restore request decided, a plan change decided, a
     * subscription past due. The bell reads these beside the agency's own
     * `notifications` (TS-6).
     *
     * Central, not in the agency's database (decided 2026-11-22): the platform
     * writes into a tenant database only its lifecycle notices
     * (AuditRecorder::recordTenantLifecycleNotice()), and these are about
     * plans, billing and restores, whose records are central anyway.
     *
     * Per agency, not per user: it is meant for whoever is the agency's Admin
     * when they look. Never plan-gated: an agency with no plan still hears
     * from the platform.
     */
    public function up(): void
    {
        Schema::create('platform_notices', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');

            $table->unsignedTinyInteger('notice_type');

            $table->string('title');
            $table->text('message');

            // Morph-map alias and id of the central record it is about.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->timestamp('read_at')->nullable();

            // Bare actor column with no foreign key, like every other actor
            // column: the notice must outlive the person who read it.
            $table->foreignId('read_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The bell: the agency's unread notices, newest first.
            $table->index(['tenant_id', 'read_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_notices');
    }
};
