<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-7.4 — dedup stamp for the "your post goes out soon" push reminder, mirroring the email
 * reminder's `reminder_sent_at` so the every-5-minutes sweep never pushes the same target twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->timestamp('push_reminder_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('push_reminder_sent_at');
        });
    }
};
