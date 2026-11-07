<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-4.1 — competitor benchmarking. Deliberately the same shape as
 * `social_account_metrics`: one row per profile per day, upserted, so a re-run overwrites rather
 * than duplicating and the series can never double-count.
 *
 * A tracked profile is a handle we do **not** own. Nothing here stores content — only the three
 * public figures a benchmark needs — and a day a platform wouldn't answer for simply has **no
 * row**, which is how a gap stays a gap instead of becoming a zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('handle', 120);
            $table->string('label', 120)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            // One brand can't track the same handle on the same platform twice.
            $table->unique(['client_id', 'platform', 'handle']);
            $table->index('org_id');
            $table->index('added_by');
        });

        Schema::create('tracked_profile_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('tracked_profile_id')->constrained('tracked_profiles')->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('post_count')->nullable();
            // Interactions per post, rounded: a fraction of a like means nothing to a reader.
            $table->unsignedBigInteger('avg_engagement')->nullable();
            $table->timestamp('fetched_at');

            $table->unique(['tracked_profile_id', 'captured_on']);
            $table->index(['org_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_profile_metrics');
        Schema::dropIfExists('tracked_profiles');
    }
};
