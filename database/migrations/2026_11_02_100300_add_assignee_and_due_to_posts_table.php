<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-5.4 — who owns a post and when it's due. `assignee_id` is the person doing the work,
 * `reviewer_id` the internal reviewer, and `due_at` the internal deadline (distinct from the
 * scheduled publish time). These drive "My work" and target notifications at a person rather than
 * the whole org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('reviewer_id');

            // IndexCoverageTest: every foreign-key column must lead an index. The composite also
            // backs "my work" (assigned to me, soonest due first).
            $table->index(['assignee_id', 'due_at']);
            $table->index('reviewer_id');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn('due_at');
        });
    }
};
