<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-7.1 (dev plan Step 2.9) — the AI feedback loop. One thumbs up/down (+ optional reason)
     * per user per AI subject (a persisted trend, idea, or a post whose caption came from AI). The
     * polymorphic subject lets every AI surface reuse the same table, and the unique index makes the
     * endpoint idempotent per user/subject (a second click updates the rating, never duplicates it).
     */
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'up' | 'down' — kept as a short string rather than a bool so an "applied" implicit
            // signal or future ratings can extend it without a schema change.
            $table->string('rating', 16);
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'subject_type', 'subject_id'], 'ai_feedback_user_subject_unique');
            $table->index(['org_id', 'subject_type', 'subject_id'], 'ai_feedback_org_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
