<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-8.1 — a campaign's fee and how it's billed; Step C-8.2 — invoice lines that bill a
 * campaign; Step C-8.3 — AI usage attributed to a campaign; Step C-4.4 — each client's approval
 * lead time (working days) used to derive deliverable due dates.
 *
 * `fee_amount` is a decimal in the client's currency, matching `invoice_items.price` and
 * `clients.retainer_amount`, so fees and invoice lines compare without conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('billing_type')->nullable()->after('utm_campaign');
            $table->decimal('fee_amount', 12, 2)->nullable()->after('billing_type');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('post_id')->constrained('campaigns')->nullOnDelete();
            $table->index('campaign_id');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('client_id')->constrained('campaigns')->nullOnDelete();
            $table->index('campaign_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedTinyInteger('approval_lead_days')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('approval_lead_days');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['billing_type', 'fee_amount']);
        });
    }
};
