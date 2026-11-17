<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Native shell (Capacitor) — a phone's FCM device token is stored beside the browser subscriptions
 * rather than in a second table, so every push sender keeps looping one relation. `platform` is
 * null for a browser (web push) and `android` / `ios` for a device token, which lives in
 * `endpoint` and has no WebCrypto keys — hence those two columns become nullable. `updated_at` is
 * the "last seen" stamp (the app re-registers on every start).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('platform', 16)->nullable()->after('user_id');
            $table->string('public_key')->nullable()->change();
            $table->string('auth_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
