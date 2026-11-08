<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-4.1 — an organization's own Twilio WhatsApp sender.
 *
 * The auth token is a credential, so it is cast `encrypted` and `Hidden` on the model, exactly
 * like `upload_post_key`. `whatsapp_enabled` is the Owner's off switch: false means
 * `WhatsAppCredentials::isConfiguredFor()` reports unconfigured however complete the rest is, so
 * turning WhatsApp off never needs the credentials deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('chat_webhook_url');
            $table->string('whatsapp_account_sid')->nullable()->after('whatsapp_enabled');
            $table->text('whatsapp_auth_token')->nullable()->after('whatsapp_account_sid');
            // E.164, without Twilio's `whatsapp:` prefix — that belongs to the wire format.
            $table->string('whatsapp_from', 32)->nullable()->after('whatsapp_auth_token');
            $table->string('whatsapp_messaging_service_sid')->nullable()->after('whatsapp_from');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_enabled',
                'whatsapp_account_sid',
                'whatsapp_auth_token',
                'whatsapp_from',
                'whatsapp_messaging_service_sid',
            ]);
        });
    }
};
