<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-1.2 — a client contact's WhatsApp consent.
 *
 * Three columns rather than one boolean, because consent is a thing you may have to *evidence*:
 * Meta's policy and most privacy regimes want to know when it was given and how it was obtained.
 * `whatsapp_consent_source` is 'portal' (they ticked it themselves), 'staff' (somebody at the
 * agency ticked it for them, and `whatsapp_consent_by` records who) or 'import'.
 *
 * Default false, for every existing row: nobody is opted in by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->boolean('receives_whatsapp')->default(false)->after('receives_invoices');
            $table->timestamp('whatsapp_consent_at')->nullable()->after('receives_whatsapp');
            $table->string('whatsapp_consent_source', 16)->nullable()->after('whatsapp_consent_at');
            $table->foreignId('whatsapp_consent_by')->nullable()->after('whatsapp_consent_source')
                ->constrained('users')->nullOnDelete();
            $table->index('whatsapp_consent_by');
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_consent_by');
            $table->dropColumn(['receives_whatsapp', 'whatsapp_consent_at', 'whatsapp_consent_source']);
        });
    }
};
