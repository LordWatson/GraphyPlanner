<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-7.3 — the affiliate/referral programme.
 *
 * **Attribution lives on `organizations`, not in a join table.** The organization is already the
 * Cashier billable, so it is the thing a commission is owed on; a separate attribution table would
 * be a second place for the same one-to-one fact to disagree with itself. `referred_at` is kept
 * beside it because "when" is what decides whether a cookie window had expired.
 *
 * A payout is a **record of money actually sent**, not a calculation. What is owed is derived from
 * the attributed orgs' subscriptions whenever somebody asks; this table only says what has been
 * paid, so nothing has to be recomputed to know the balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_partners', function (Blueprint $table) {
            $table->id();
            // The code people put in a link. Case-insensitive in practice (normalised on the way
            // in), so the unique index is on the normalised form.
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('commission_percent')->default(20);
            $table->text('payout_details')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_partner_id')->constrained('referral_partners')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('paid_at')->nullable();
            $table->string('reference', 120)->nullable();
            $table->timestamps();

            $table->index('referral_partner_id');
            $table->index('paid_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            // `nullOnDelete`: removing a partner must never take their referred customers with it.
            $table->foreignId('referral_partner_id')->nullable()->after('slug')
                ->constrained('referral_partners')->nullOnDelete();
            $table->timestamp('referred_at')->nullable()->after('referral_partner_id');

            $table->index('referral_partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['referral_partner_id']);
            $table->dropConstrainedForeignId('referral_partner_id');
            $table->dropColumn('referred_at');
        });

        Schema::dropIfExists('referral_payouts');
        Schema::dropIfExists('referral_partners');
    }
};
