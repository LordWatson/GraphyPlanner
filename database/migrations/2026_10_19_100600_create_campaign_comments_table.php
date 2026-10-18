<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-5.3 — one campaign-level conversation shared by the studio and the client portal.
 * `internal_only` comments never reach the portal (same flag as post comments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal_only')->default(false);
            $table->timestamps();

            $table->index(['campaign_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_comments');
    }
};
