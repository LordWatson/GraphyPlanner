<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-3.1 / I-10.1 (Phase 12) — engagement metrics.
 *
 * - `post_targets` keeps what the vendor tells us about the live post (its upload request id, the
 *   network's own post id and URL) and when it went live, so metrics can be fetched for it later
 *   and best-time insights (M-3.2) can bucket by the real publish moment.
 * - `post_target_metrics` is append-only: one row per sync, so trends stay queryable.
 *   `post_targets.latest_metrics_id` points at the newest row, so reports join one row per target.
 * - `social_account_metrics` holds one row per account per local day (followers, reach…), upserted,
 *   for follower growth and reach over time.
 *
 * Every metric is nullable: a platform that doesn't report a field leaves it null, which the app
 * shows as "not available" rather than zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->string('vendor_request_id', 128)->nullable()->after('external_post_id');
            $table->string('platform_post_id', 191)->nullable()->after('vendor_request_id');
            $table->string('post_url', 2048)->nullable()->after('platform_post_id');
            $table->timestamp('published_at')->nullable()->after('post_url');
            $table->timestamp('metrics_synced_at')->nullable()->after('published_at');
            $table->string('metrics_error', 500)->nullable()->after('metrics_synced_at');

            $table->index(['status', 'published_at']);
        });

        Schema::create('post_target_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('engagement')->nullable();
            $table->string('source', 32);
            $table->timestamp('fetched_at');

            $table->index(['post_target_id', 'fetched_at']);
            $table->index(['org_id', 'fetched_at']);
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->foreignId('latest_metrics_id')->nullable()->after('metrics_error')
                ->constrained('post_target_metrics')->nullOnDelete();
            $table->index('latest_metrics_id');
        });

        Schema::create('social_account_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('profile_views')->nullable();
            $table->timestamp('fetched_at');

            $table->unique(['social_account_id', 'metric_date']);
            $table->index(['org_id', 'metric_date']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('metrics_synced_at')->nullable();
            $table->string('metrics_error', 500)->nullable();
        });

        // Targets that went live before this column existed: their last update is when the
        // vendor confirmed them (SyncPublishStatusAction is the only path to `published`).
        DB::table('post_targets')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['metrics_synced_at', 'metrics_error']);
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_metrics_id');
        });

        Schema::dropIfExists('social_account_metrics');
        Schema::dropIfExists('post_target_metrics');

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropColumn(['vendor_request_id', 'platform_post_id', 'post_url', 'published_at', 'metrics_synced_at', 'metrics_error']);
        });
    }
};
