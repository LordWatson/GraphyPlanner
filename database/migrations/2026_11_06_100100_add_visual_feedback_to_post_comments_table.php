<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.4 — visual feedback. A comment can now be pinned to a point on an image (x/y), a
     * moment in a video (t), or a range of the caption; carry a suggested caption edit the team can
     * accept in one click; be a reply within a thread (`parent_id`); and be resolved. Unresolved
     * *feedback* threads (a pin or a suggestion) block a post from being approved.
     */
    public function up(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            // image / video / caption — null for a plain (unanchored) comment.
            $table->string('anchor_type', 16)->nullable()->after('body');
            // {asset_id,x,y} | {asset_id,t} | {channel,start,end,quote}
            $table->json('anchor')->nullable()->after('anchor_type');
            // A proposed replacement for the caption the comment is anchored to.
            $table->text('suggested_edit')->nullable()->after('anchor');
            $table->timestamp('suggestion_resolved_at')->nullable()->after('suggested_edit');
            $table->foreignId('parent_id')->nullable()->after('post_id')->constrained('post_comments')->cascadeOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('internal_only');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();

            $table->index('parent_id');
            $table->index('resolved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['anchor_type', 'anchor', 'suggested_edit', 'suggestion_resolved_at', 'resolved_at']);
        });
    }
};
