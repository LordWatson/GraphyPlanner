<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 18.1 — an Instagram grid plan: one client, one of its Instagram accounts (optionally a
 * campaign), its approval stage, and a `version` bumped on every layout change so stale writes
 * (a reorder from an old tab, a client approving a grid that changed) can be refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('draft');
            $table->text('review_message')->nullable();
            $table->unsignedTinyInteger('pinned_count')->default(0);
            $table->boolean('include_live_feed')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->json('checklist_snapshot')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_layout_hash', 64)->nullable();
            $table->timestamp('order_risk_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index(['org_id', 'status']);
            $table->index('social_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_plans');
    }
};
