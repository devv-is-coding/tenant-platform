<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            // Real columns rather than keys in `data`: the super admin console
            // filters and sorts on status, and MariaDB cannot index a value inside
            // the JSON blob. Defaults to Pending (1) - going live is a deliberate
            // act by a super admin, not a side effect of an INSERT.
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->string('status_reason')->nullable();
            $table->timestamp('status_changed_on')->nullable();

            // The super admin who last changed the status. Bare column with no
            // foreign key, like every other actor column: the record must outlive
            // the operator.
            $table->foreignId('status_changed_by')->nullable()->index();

            $table->timestamp('created_on')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->softDeletes('deleted_on');
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
