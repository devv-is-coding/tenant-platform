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
            // The agency the user belongs to: admins, managers and staff work for
            // it, and a customer signed up in its app. Only super admins have none.
            $table->string('tenant_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('tenant_id');
            $table->softDeletes()->after('updated_at');

            // RESTRICT, not CASCADE: hard-deleting an agency must never silently
            // take its customers' accounts with it. The app only soft-deletes
            // agencies, so this guards a force delete.
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
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
