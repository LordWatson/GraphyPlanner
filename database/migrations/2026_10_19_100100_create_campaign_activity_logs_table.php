<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-0.3 — one row per campaign event (status transitions, brief decisions, pauses, shifts…),
 * written by every campaign Action. Append-only, so only `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('from', 64)->nullable();
            $table->string('to', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_activity_logs');
    }
};
