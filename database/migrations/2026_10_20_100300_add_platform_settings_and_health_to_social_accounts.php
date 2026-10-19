<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-3.4 / M-2.6 — per-account settings a platform needs before it will publish (the
     * registry's `required_settings`, e.g. the Pinterest board, or a Google Business location).
     *
     * Step I-3.9 / M-2.7 — the hourly health check's last run and error, shown on the account, and
     * which token-expiry warning (7 days / 1 day) was last sent, so each goes out once.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->json('platform_settings')->nullable()->after('posting_windows');
            $table->timestamp('last_health_check_at')->nullable()->after('connected_at');
            $table->text('last_health_error')->nullable()->after('last_health_check_at');
            $table->string('token_expiry_warning', 8)->nullable()->after('meta_access_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['platform_settings', 'last_health_check_at', 'last_health_error', 'token_expiry_warning']);
        });
    }
};
