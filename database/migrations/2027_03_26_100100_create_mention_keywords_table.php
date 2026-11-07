<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mention alerts: the words a studio wants to hear about the moment they appear in a comment.
 *
 * The narrow, honest half of "social listening" — it reads only the comments the app already
 * ingests for connected accounts, so it makes no claim about the open web. Deliberately org-wide
 * (no `client_id`): an agency watching "refund" wants it wherever it is said, and the alert names
 * the client it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mention_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('term', 80);
            // How often this word has actually fired, so an owner can see which ones earn their
            // place instead of guessing.
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamps();

            // One row per word per organisation; two agencies may watch the same word. No NULLs in
            // either column, so this behaves identically on MySQL and SQLite.
            $table->unique(['org_id', 'term']);
            // `IndexCoverageTest` requires every foreign key to lead an index; without it a
            // staff-member delete would scan this table to null the column.
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mention_keywords');
    }
};
