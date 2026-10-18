<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-7.1 — KPI targets per campaign, and Step C-7.3 — the wrap report (one per campaign) whose
 * KPI snapshot is frozen the moment it's shared with the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('metric', 50);
            $table->decimal('target', 14, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'metric']);
        });

        Schema::create('campaign_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained('campaigns')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->json('highlights')->nullable();
            $table->text('learnings')->nullable();
            $table->text('next_steps')->nullable();
            $table->json('kpi_snapshot')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('shared_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_reports');
        Schema::dropIfExists('campaign_kpis');
    }
};
