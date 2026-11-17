<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step S-2.2 — one row per attempt at posting an assisted Story by hand.
 *
 * A handoff belongs to one publish attempt (decision D5): `publish_attempt_id` copies the
 * channel's, so a retry or a re-claim makes the old row stale without anybody having to find and
 * close it. Only the hash of the link's token is stored (D6). An open handoff is
 * `completed_at IS NULL AND outcome IS NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->string('publish_attempt_id', 36);
            $table->string('token_hash', 64)->unique();
            $table->string('recipient_type', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at');
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('nudged_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('shared_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_via', 16)->nullable();
            $table->string('outcome', 24)->nullable();
            $table->string('problem_note', 500)->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['post_target_id', 'publish_attempt_id']);
            $table->index('due_at');
            $table->index('expires_at');
            // The sweep and the "Stories to post" lists read open rows only.
            $table->index(['completed_at', 'due_at']);

            // Step S-6.4: every foreign key leads an index (`IndexCoverageTest`; MySQL would add
            // bare ones on its own, SQLite would not), shaped for what reads them: reporting by
            // organization and outcome, a client's or a post's handoffs, one person's open ones.
            $table->index(['org_id', 'outcome', 'completed_at']);
            $table->index(['client_id', 'due_at']);
            $table->index(['post_id', 'outcome']);
            $table->index(['user_id', 'completed_at']);
            $table->index('completed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_handoffs');
    }
};
