<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-2.1 — the structured campaign brief (1:1 with a campaign) and Step C-2.3 — the frozen
 * revisions sent to the client, each with its content hash and the client's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained('campaigns')->cascadeOnDelete();
            $table->text('background')->nullable();
            $table->text('objective_detail')->nullable();
            $table->text('audience')->nullable();
            $table->json('key_messages')->nullable();
            $table->text('offer')->nullable();
            $table->string('call_to_action', 255)->nullable();
            $table->json('mandatories')->nullable();
            $table->json('avoid')->nullable();
            $table->text('deliverables_summary')->nullable();
            $table->text('success_measures')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('approved_by');
            $table->index('updated_by');
        });

        Schema::create('campaign_brief_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_brief_id')->constrained('campaign_briefs')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('content');
            $table->string('content_hash', 64);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->string('decision')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_brief_id', 'number']);
            $table->index('sent_by');
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_brief_revisions');
        Schema::dropIfExists('campaign_briefs');
    }
};
