<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping found by `IndexCoverageTest` while Phase CG-4 landed: `plan_audit_logs.actor_user_id`
 * (Phase CG-2) is a foreign key with no index leading on it, so "what did this admin change?" and
 * the cascade on a deleted user both scan the table. Its own migration rather than an edit to the
 * CG-2 one, which has already run everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_audit_logs', function (Blueprint $table) {
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('plan_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_user_id']);
        });
    }
};
