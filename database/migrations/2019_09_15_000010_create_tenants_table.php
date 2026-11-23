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
            $table->timestamp('status_changed_at')->nullable();

            // The super admin who last changed the status. Bare column with no
            // foreign key, like every other actor column: the record must outlive
            // the operator.
            $table->foreignId('status_changed_by')->nullable()->index();

            // The agency as a customer of the platform's own Stripe account,
            // for paying its plan and invoices by card (TA-27). Created on the
            // first card payment.
            $table->string('stripe_customer_id')->nullable();

            // The card the agency's Admin saved for auto-pay (TA-33): Stripe's
            // id to charge it by, and only what the billing page shows of it.
            // `auto_pay_since` is when auto-pay was turned on, NULL while off:
            // only invoices issued from then on are charged automatically.
            $table->string('stripe_payment_method_id')->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->timestamp('auto_pay_since')->nullable();

            $table->timestamps();
            $table->softDeletes();
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
