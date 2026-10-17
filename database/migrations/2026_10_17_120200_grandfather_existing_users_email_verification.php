<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 15.2.3 turns on email verification (`User implements MustVerifyEmail`). Accounts that
     * already exist were created by invitation links (which prove inbox ownership) or seeders, so
     * they're grandfathered as verified rather than locked out on their next request.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        // Irreversible data backfill — nothing to undo.
    }
};
