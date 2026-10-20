<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 18.1 — a grid plan's activity log and comments, the same shapes as their post
 * counterparts so the discussion components are reused. A comment may be pinned to one tile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_plan_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_plan_id')->constrained('grid_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->foreignId('review_token_id')->nullable()->constrained('review_tokens')->nullOnDelete();
            $table->timestamps();

            $table->index(['grid_plan_id', 'created_at']);
        });

        Schema::create('grid_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_plan_id')->constrained('grid_plans')->cascadeOnDelete();
            $table->foreignId('grid_tile_id')->nullable()->constrained('grid_tiles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal_only')->default(false);
            $table->timestamps();

            $table->index(['grid_plan_id', 'created_at']);
        });

        Schema::create('grid_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_comment_id')->constrained('grid_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['grid_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_comment_mentions');
        Schema::dropIfExists('grid_comments');
        Schema::dropIfExists('grid_plan_activity_logs');
    }
};
