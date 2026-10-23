<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tier each plan feature starts at, where the super admin has moved it.
     *
     * Overrides only: a feature with no row starts at its PlanFeature
     * defaultTier(), so nothing is seeded and the table starts empty. The
     * feature list itself is code, because a feature exists only where code
     * checks for it.
     *
     * What a tier includes is read live, not snapshotted per subscription, so
     * moving a feature here reaches every agency on that tier at once. That is
     * why a move is audited with its reason.
     *
     * `feature` is unique across soft-deleted rows too, so writes reuse a
     * soft-deleted row rather than adding a second one.
     */
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();

            $table->string('feature', 64)->unique();
            $table->unsignedTinyInteger('minimum_tier');

            // The super admin who last moved the feature. Bare column with no
            // foreign key, like every other actor column: the setting must
            // outlive the operator who chose it.
            $table->foreignId('updated_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
