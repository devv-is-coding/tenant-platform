<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The central discovery projection: one row per published item, copied out
     * of whichever tenant database owns it.
     *
     * Customers must search across every agency, but listings live in isolated
     * tenant databases. Request-time fan-out dies at around twenty tenants, and
     * the tenancy package's resource syncing is built for central-to-many
     * mirroring rather than many-to-one aggregation. So published items are
     * projected here by queued domain events, reconciled nightly, with
     * `synced_at` making staleness observable. A search engine would later index
     * this same table, which makes adopting one a config change rather than a
     * re-architecture.
     *
     * THE INVARIANT, which must never be violated: this table is for DISCOVERY
     * ONLY. It is never the source of truth for price or availability. Both the
     * detail page and the booking transaction re-read those authoritatively
     * inside the tenant database. Staleness can therefore only produce "search
     * said 499, the detail page says 549" - irritating, never a financial error.
     *
     * Marketplace queries must also join `tenants.status`, so a suspended or
     * closed agency disappears from the marketplace. Tenant status is
     * deliberately NOT denormalised here: a copy would drift and would need
     * every listing re-projected on every status change, and the foreign key is
     * in this same database so the join is free.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            // A ULID, not a tenant-local id, because this row is addressed from
            // the central marketplace where tenant ids collide.
            $table->ulid('id')->primary();

            $table->string('tenant_id');

            // A tenant-local autoincrement, deliberately not a foreign key - it
            // points into another database, and identical ids across tenants are
            // the normal case, which is why the unique key below is composite.
            $table->unsignedBigInteger('item_id');

            $table->unsignedTinyInteger('item_type');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Denormalised so a marketplace card renders without reaching into
            // any tenant database.
            $table->string('tenant_name');
            $table->string('locality')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->char('currency', 3);

            // A range, because an item has many options. Goes stale on OPTION
            // edits as well as item edits, which is the easy hook to miss.
            $table->unsignedBigInteger('price_min_minor')->nullable();
            $table->unsignedBigInteger('price_max_minor')->nullable();

            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);

            // A coarse envelope only. Cross-tenant *date* availability is not
            // solved here: filter centrally down to one page of listings, then
            // fan out to only those tenants to confirm exact dates.
            $table->date('available_from')->nullable();
            $table->date('available_to')->nullable();

            // Enough to draw the card's thumbnail; see media for why a disk and
            // a path rather than a URL.
            $table->string('featured_media_disk', 64)->nullable();
            $table->string('featured_media_path', 500)->nullable();

            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();

            // Makes projection drift observable; a max() over this column is the
            // health check for a dead queue worker.
            $table->timestamp('synced_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Running the sync job twice must yield one row.
            $table->unique(['tenant_id', 'item_id']);

            $table->index(['item_type', 'price_min_minor']);
            $table->index(['country_code', 'locality']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
