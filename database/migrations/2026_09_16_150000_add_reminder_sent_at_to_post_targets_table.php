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
            // Tracks whether the "post scheduled soon" reminder email has already been sent for
            // this target, so `posts:send-upcoming-reminders` never emails the same target twice.
            $table->timestamp('reminder_sent_at')->nullable()->after('scheduled_at_utc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
