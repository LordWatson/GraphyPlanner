<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-5.3 — a content history for posts. Each save that changes the content writes one revision
 * (a snapshot of caption, hashtags, music, location, link, first comment, media format, the
 * per-channel overrides and the attached media). `shared_at` marks a revision the client was shown
 * (recorded when a post enters `waiting_client`), so the review link and portal can show "what
 * changed since you last saw it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            // Set when this revision is the version the client was shown (entered waiting_client).
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            // IndexCoverageTest: every foreign-key column must lead an index.
            $table->index(['post_id', 'id']);
            $table->index('org_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_revisions');
    }
};
