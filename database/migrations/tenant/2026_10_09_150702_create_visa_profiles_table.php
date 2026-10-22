<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed attributes for a Visa item, replacing the legacy EAV rows named
     * "Processing Time", "Requirements" and "Validity".
     *
     * Processing time becomes two sortable integers rather than prose, so
     * "visas processed within a week" is a query instead of a string match.
     */
    public function up(): void
    {
        Schema::create('visa_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('processing_days_min');
            $table->unsignedSmallInteger('processing_days_max');
            $table->unsignedSmallInteger('validity_days');

            $table->json('requirements');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visa_profiles');
    }
};
