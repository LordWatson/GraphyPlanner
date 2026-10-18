<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            // Step I-1.3: one id per publish attempt of this target. Sent to the vendor as the
            // idempotency key, and checked by `PublishPostTargetJob` so a stale or retried job
            // can never post the same attempt twice.
            $table->uuid('publish_attempt_id')->nullable()->after('error');
            // Set right before the vendor call. A retried job that finds it set without an
            // `external_post_id` can't know whether the vendor received the request, so it fails
            // the target instead of risking a double post.
            $table->timestamp('publish_requested_at')->nullable()->after('publish_attempt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['publish_attempt_id', 'publish_requested_at']);
        });
    }
};
