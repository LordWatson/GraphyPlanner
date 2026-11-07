<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-5.1 — read-only paid-ads reporting, following `social_accounts` /
 * `social_account_metrics` exactly: one connection row per ad account, one metrics row per account
 * per day, upserted.
 *
 * **CPC and CPA are not columns.** They are spend ÷ clicks and spend ÷ conversions, derived where
 * they are shown, so a stored ratio can never disagree with the figures it came from.
 *
 * Money is minor units in the ad account's own currency, like every other amount here. There is no
 * conversion: a report shows each account in the currency it spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('external_account_id', 191);
            $table->string('name', 191)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('connection_status', 32);
            $table->timestamp('metrics_synced_at')->nullable();
            $table->string('metrics_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'platform', 'external_account_id']);
            $table->index('client_id');
            $table->index(['org_id', 'connection_status']);
        });

        Schema::create('ad_account_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedBigInteger('spend_minor')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('conversions')->nullable();
            $table->timestamp('fetched_at');

            $table->unique(['ad_account_id', 'captured_on']);
            $table->index(['org_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_account_metrics');
        Schema::dropIfExists('ad_accounts');
    }
};
