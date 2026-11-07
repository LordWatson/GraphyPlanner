<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress for a broadcast that is now sent by a job rather than inside the request.
 *
 * `recipients` already recorded how many it was addressed to. Sending used to happen in a
 * `foreach` in the web request — one queue push per contact, so a 5,000-contact list meant 5,000
 * sequential writes before the response, and a bigger one hit `max_execution_time` with no record
 * of how far it got. These columns are what makes "2,300 of 5,000 sent" sayable, and what lets a
 * retry know where it stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_broadcasts', function (Blueprint $table) {
            $table->unsignedInteger('sent_count')->default(0)->after('recipients');
            $table->unsignedInteger('failed_count')->default(0)->after('sent_count');
            // The highest contact id already handled. A retry resumes from it rather than sending
            // the first chunks a second time — and an id is the right marker because the job pages
            // through recipients ordered by id (`chunkById`), so "past this point" is exact.
            $table->unsignedBigInteger('last_contact_id')->nullable()->after('failed_count');
            $table->timestamp('finished_at')->nullable()->after('last_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('audience_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['sent_count', 'failed_count', 'last_contact_id', 'finished_at']);
        });
    }
};
