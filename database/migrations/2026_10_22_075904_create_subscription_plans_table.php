<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's price list: the plans a super admin offers agencies.
     *
     * A plan is a template, not a contract. Putting an agency on a plan copies
     * its name, tier, price and interval onto the subscription, so repricing a plan
     * here changes what the next agency agrees to, never what an existing one
     * already did.
     *
     * Names are unique among live plans only, enforced in validation rather
     * than by an index: a soft-deleted plan still holds its row, and an index
     * would stop its name from ever being offered again.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // The feature tier the plan sells (PlanTier). A tier may have
            // several plans, such as Pro Monthly and Pro Annual.
            $table->unsignedTinyInteger('tier')->index();

            $table->unsignedBigInteger('amount_minor');

            // The platform's billing currency when the plan was priced, from
            // config, so the plan still reads correctly if that ever changes.
            $table->char('currency', 3);

            $table->unsignedTinyInteger('interval');

            // The super admin who created the plan. Bare column with no foreign
            // key, like every other actor column: the price list must outlive
            // the operator who wrote it.
            $table->foreignId('created_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
