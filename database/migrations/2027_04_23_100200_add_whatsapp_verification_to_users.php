<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-1.1 — the one-time code that verifies a member's own WhatsApp number.
 *
 * The code is **hashed** at rest, like `ReviewToken`: it is a credential for the few minutes it
 * lives, and a leaked database row should not let somebody else confirm a number. The sent-at
 * stamp carries the expiry, and the attempt counter is what stops a six-digit code being guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_verification_code')->nullable()->after('phone_verified_at');
            $table->timestamp('phone_verification_sent_at')->nullable()->after('phone_verification_code');
            $table->unsignedTinyInteger('phone_verification_attempts')->default(0)->after('phone_verification_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_verification_code', 'phone_verification_sent_at', 'phone_verification_attempts']);
        });
    }
};
