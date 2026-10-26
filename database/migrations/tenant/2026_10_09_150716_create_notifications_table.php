<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-app notification feed, ported from the legacy `notifications`
     * table: the staff bell, and the feed in the customer's app.
     *
     * Per agency, because everyone it notifies belongs to one agency - its
     * staff, and its customers, who belong to one agency at a time - so the
     * agency is the table's sole reader and writer, and there is no tenant
     * column to filter on.
     *
     * This deliberately diverges from Laravel's `DatabaseNotification` shape,
     * which stores everything in one JSON blob. Typed columns turn "unread
     * booking notifications" into a column filter instead of a JSON scan. The
     * cost: `$user->notifications()` from the `Notifiable` trait will NOT work
     * against this table, so the feed needs its own model and relation. The
     * trait stays on the user model for mail delivery only.
     *
     * Every row is written from a domain event, never from UI code, and any
     * money in a body is formatted through the money value object - the legacy
     * shipped a customer a notification containing the literal text
     * 30.00999999999999801048033987, which is defect 2 reaching a real person.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // A central user, staff or customer, so not a real foreign key.
            // Mirrors sessions.user_id.
            $table->foreignId('user_id');

            $table->unsignedTinyInteger('notification_type');

            $table->string('title');
            $table->text('message');

            // Morph-map alias and id, from the legacy `relatable_table` and
            // `related_id`. An alias, never an FQCN.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The bell: this user's unread notifications, newest first.
            $table->index(['user_id', 'is_read', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
