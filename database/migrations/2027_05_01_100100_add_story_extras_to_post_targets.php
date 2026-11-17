<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step S-1.2 — a Story channel can carry extras (a link, a poll, a mention, …) that Instagram's
 * publishing API cannot add, so the channel is posted by hand instead (`story-extras.md`).
 *
 * `handoff_required` is the stored form of "this is a Story with at least one extra" (decision
 * D3): the two publish jobs filter on it in SQL. It is written in exactly one place,
 * `SyncTargetStoryExtrasAction`. `handoff_recipient` is the channel's own answer to "who posts
 * it"; null means the client's default, which is the new `clients` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->json('story_extras')->nullable()->after('format');
            $table->boolean('handoff_required')->default(false)->after('story_extras');
            $table->string('handoff_recipient', 16)->nullable()->after('handoff_required');
            $table->index('handoff_required');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('story_handoff_recipient', 16)->default('team');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('story_handoff_recipient');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropIndex(['handoff_required']);
            $table->dropColumn(['story_extras', 'handoff_required', 'handoff_recipient']);
        });
    }
};
