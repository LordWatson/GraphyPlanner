<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-8.2 — a lightweight "last seen" stamp for the partner usage view's weekly-active-users
     * figure. Written at most once every few minutes per user by `TrackUserActivity` (cache-guarded),
     * so it costs a request almost nothing. Indexed with `org_id` is not needed — the WAU query
     * filters `org_id IN (…)` then this column, so a plain index on the timestamp is enough.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('email_verified_at');
            $table->index('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn('last_active_at');
        });
    }
};
