<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase CG-4 — the creator store. Sales go through Stripe Connect behind
 * `App\Contracts\PaymentProvider`; our own subscriptions stay on Cashier. Per the plan's Principle 4
 * the two share no tables: the Connect side has its own account row, its own webhook ledger
 * (`connect_webhook_events`, not Cashier's `stripe_webhook_events`) and its own signing secret.
 *
 * - `payment_accounts`: one connected account per org, with the capability flags Stripe reports.
 *   No secrets live here — only the account id Stripe gives us.
 * - `products`: what's for sale. `price_minor` is always minor units (pence/cents) so money is
 *   never a float; a tip's price is a suggestion the buyer can beat. `settings` carries the
 *   per-type payload (download limit/expiry, booking availability, the Q&A answer window).
 * - `orders`: one purchase. `external_session_id` is unique, which is what makes a replayed
 *   `checkout.session.completed` a no-op. `authorised_until` is only set for a paid question —
 *   past it the authorisation is cancelled rather than captured.
 * - `order_events`: the audit trail (every status change, every download, the refund).
 * - `coupons`: percent or fixed amount off, optionally scoped to one product.
 * - `bookings`: the slot a booking order holds. `unique(product_id, starts_at)` is the real
 *   double-booking guard — two buyers racing for the last slot can't both win.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('external_account_id')->unique();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->json('requirements_due')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->string('status', 16)->default('draft');
            $table->foreignId('cover_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            // The file a download delivers. Private disk only — the buyer never gets its real URL.
            $table->foreignId('download_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            // Every foreign key leads an index (IndexCoverageTest).
            $table->index('org_id');
            $table->index('cover_asset_id');
            $table->index('download_asset_id');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            // Null = the whole store.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('code', 32);
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_minor')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions')->default(0);
            $table->timestamps();

            $table->unique(['org_id', 'code']);
            $table->index('product_id');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('buyer_email');
            $table->string('buyer_name')->nullable();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->string('external_session_id')->nullable()->unique();
            $table->string('external_payment_id')->nullable();
            // A tip's note, or the question on a paid question.
            $table->text('message')->nullable();
            $table->text('answer')->nullable();
            $table->timestamp('authorised_until')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedSmallInteger('download_count')->default(0);
            $table->timestamps();

            $table->index(['org_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['buyer_email', 'created_at']);
            $table->index('coupon_id');
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 48);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('booked');
            $table->timestamps();

            // Two buyers can't hold the same slot, whatever the application layer does.
            $table->unique(['product_id', 'starts_at']);
            $table->index('order_id');
        });

        Schema::create('connect_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type', 64);
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_webhook_events');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('products');
        Schema::dropIfExists('payment_accounts');
    }
};
