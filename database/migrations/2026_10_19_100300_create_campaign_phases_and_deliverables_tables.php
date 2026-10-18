<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-3.1 — campaign phases (teaser, launch, sustain, wrap) and Step C-3.2 — the deliverables
 * matrix: planned slots that become a post only when work starts (plan §1.3.1). `post_id` is
 * unique so a slot can be started once; deleting the post frees the slot again. Asset
 * requirements (C-4.1) and the assignee (C-4.4) live on the slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('colour')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'sort']);
        });

        Schema::create('campaign_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_phase_id')->nullable()->constrained('campaign_phases')->nullOnDelete();
            $table->string('title', 255);
            $table->string('platform');
            $table->string('format', 50)->nullable();
            $table->string('pillar', 100)->nullable();
            $table->date('planned_for')->nullable();
            $table->string('planned_time', 5)->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('asset_requirements')->nullable();
            $table->foreignId('post_id')->nullable()->unique()->constrained('posts')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['campaign_id', 'planned_for']);
            $table->index(['assignee_id', 'planned_for']);
            $table->index('campaign_phase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_deliverables');
        Schema::dropIfExists('campaign_phases');
    }
};
