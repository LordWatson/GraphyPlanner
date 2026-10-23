<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-5.8 — content recycling (Phase 11) and recurring posts.
 *
 * - `is_recyclable` / `recycle_after_days`: flag an evergreen post as reusable and surface a
 *   one-click "recycle" suggestion once its window has passed.
 * - `recycled_from_post_id`: the post this one was recycled or recurred from — stops the same
 *   suggestion firing forever and lets the recurrence job find a chain's leaf.
 * - `recurrence`: `weekly` / `monthly` (null = one-off). A recurring post drops the next
 *   occurrence as a draft after it publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('is_recyclable')->default(false)->after('checklist_snapshot');
            $table->unsignedInteger('recycle_after_days')->nullable()->after('is_recyclable');
            $table->foreignId('recycled_from_post_id')->nullable()->after('recycle_after_days')
                ->constrained('posts')->nullOnDelete();
            $table->string('recurrence')->nullable()->after('recycled_from_post_id');

            // IndexCoverageTest: every foreign-key column must lead an index. The recurrence index
            // backs the daily "create next occurrence" sweep (recurring leaves).
            $table->index('recycled_from_post_id');
            $table->index('recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recycled_from_post_id');
            $table->dropColumn(['is_recyclable', 'recycle_after_days', 'recurrence']);
        });
    }
};
