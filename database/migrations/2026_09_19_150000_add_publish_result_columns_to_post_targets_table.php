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
            // Step 1.4: per-target publish outcome, set by `PublishPostJob` once the vendor
            // adapter's `publish()` call returns for this target — mirrors `TargetResult`.
            $table->string('status')->default('pending')->after('reminder_sent_at');
            $table->string('external_post_id')->nullable()->after('status');
            $table->text('error')->nullable()->after('external_post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['status', 'external_post_id', 'error']);
        });
    }
};
