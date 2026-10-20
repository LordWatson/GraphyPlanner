<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 — every foreign-key column leads an index (MySQL adds one implicitly; SQLite doesn't),
 * as `IndexCoverageTest` requires.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        'assets' => ['source_asset_id'],
        'grid_comment_mentions' => ['user_id'],
        'grid_comments' => ['user_id', 'grid_tile_id'],
        'grid_plan_activity_logs' => ['review_token_id', 'user_id'],
        'grid_plans' => ['created_by', 'approved_by', 'campaign_id'],
        'grid_tiles' => ['cover_asset_id'],
        'review_tokens' => ['grid_plan_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasIndex($table, [$column])) {
                        $blueprint->index($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasIndex($table, ["{$table}_{$column}_index"])) {
                        $blueprint->dropIndex([$column]);
                    }
                }
            });
        }
    }
};
