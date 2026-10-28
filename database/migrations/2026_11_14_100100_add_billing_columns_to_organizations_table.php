<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase CG-2 — billing & plan columns on organizations.
 *
 *  - The four Cashier "customer" columns (Organization is the Billable), mirroring Cashier's own
 *    create_customer_columns migration but on organizations, not users.
 *  - `plan`: the org's current plan key (null → the default for its type, see config/plans.php).
 *  - `billing_enabled`: a super-admin per-org override of the global Stripe switch (null = inherit).
 *  - Temporary AI credit grant (CG-2.6): a super admin can top up an org's monthly AI allowance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Cashier customer columns.
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            // Plan & billing switch.
            $table->string('plan')->nullable()->after('type');
            $table->boolean('billing_enabled')->nullable();

            // Temporary AI credit grant (super-admin, CG-2.6).
            $table->unsignedInteger('bonus_ai_text_credits')->default(0);
            $table->timestamp('bonus_ai_credits_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
                'plan',
                'billing_enabled',
                'bonus_ai_text_credits',
                'bonus_ai_credits_expires_at',
            ]);
        });
    }
};
