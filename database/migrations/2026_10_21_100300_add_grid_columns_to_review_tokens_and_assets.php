<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 18.1 / 18.4 — a review token can carry a grid plan instead of a post (never both, checked
 * in `CreateReviewTokenAction`), and an asset made by splitting another one points back to its
 * source (the original is never changed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_tokens', function (Blueprint $table) {
            $table->foreignId('grid_plan_id')->nullable()->after('post_id')->constrained('grid_plans')->cascadeOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('source_asset_id')->nullable()->after('variant_group_id')->constrained('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_asset_id');
        });

        Schema::table('review_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grid_plan_id');
        });
    }
};
