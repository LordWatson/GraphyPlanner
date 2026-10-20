<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 18.1 — the tiles of a grid plan in display order (`position` 1 = top-left = newest; the
 * publish order is the reverse) and the media each holds. `(grid_plan_id, position)` is unique;
 * reorders rewrite positions inside one transaction (SQLite can't defer the constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_tiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_plan_id')->constrained('grid_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 16)->default('image');
            $table->string('format', 16)->default('3:4');
            $table->foreignId('cover_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('placeholder_colour', 16)->nullable();
            $table->text('team_note')->nullable();
            $table->text('caption_idea')->nullable();
            $table->date('planned_local_date')->nullable();
            $table->string('planned_local_time', 8)->nullable();
            $table->uuid('split_group_id')->nullable();
            $table->foreignId('post_id')->nullable()->unique()->constrained('posts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['grid_plan_id', 'position']);
            $table->index('split_group_id');
        });

        Schema::create('grid_tile_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_tile_id')->constrained('grid_tiles')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('crop_x', 5, 4)->default(0.5);
            $table->decimal('crop_y', 5, 4)->default(0.5);
            $table->decimal('crop_zoom', 4, 2)->default(1);
            $table->text('alt_text')->nullable();
            $table->timestamps();

            $table->unique(['grid_tile_id', 'asset_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_tile_assets');
        Schema::dropIfExists('grid_tiles');
    }
};
