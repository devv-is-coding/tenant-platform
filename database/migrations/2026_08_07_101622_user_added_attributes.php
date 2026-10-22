<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->change();

            $table->string('first_name')->after('email');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->after('middle_name');
            $table->string('contact_number', 20)->nullable()->after('last_name');
            $table->string('profile_picture')->nullable()->after('contact_number');
            $table->unsignedTinyInteger('role')->default(0)->after('profile_picture');
            // Admins, managers and staff belong to one tenant; super admins and customers do not.
            $table->string('tenant_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('tenant_id');
            $table->softDeletes()->after('updated_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);

            $table->dropColumn([
                'first_name',
                'middle_name',
                'last_name',
                'contact_number',
                'profile_picture',
                'role',
                'tenant_id',
                'is_active',
                'deleted_at',
            ]);

            $table->dropUnique(['username']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('username', 'name');
        });
    }
};
