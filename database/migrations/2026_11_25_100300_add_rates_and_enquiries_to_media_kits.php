<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step CG-5.3 — optional rates and an enquiry form on the media kit.
 *
 * - `rates`: json (`[{label, amount_minor}]`) in the org's own currency. Opt-in by being empty:
 *   a creator who would rather talk about money in an email just leaves it blank.
 * - `enquiries_enabled`: whether the public kit shows "work with me" form at all. Off by default —
 *   it is a public write surface, so nobody gets one they didn't ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_kits', function (Blueprint $table) {
            $table->json('rates')->nullable()->after('bio');
            $table->boolean('enquiries_enabled')->default(false)->after('rates');
        });
    }

    public function down(): void
    {
        Schema::table('media_kits', function (Blueprint $table) {
            $table->dropColumn(['rates', 'enquiries_enabled']);
        });
    }
};
