<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-0.4 — where a member's WhatsApp number lives.
 *
 * Two columns, because only the pair is safe: a number nobody has confirmed is a number that might
 * belong to somebody else, so `routeNotificationForWhatsApp()` returns null until
 * `phone_verified_at` is set. Step W-1.1 is what captures and verifies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // E.164, so the longest possible value is 16 characters including the `+`.
            $table->string('phone', 32)->nullable()->after('email_verified_at');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
