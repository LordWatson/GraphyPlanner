<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — the client's own tax registration, a per-client billing currency (so the retainer
 * has a currency of its own instead of being read as the org default), and the default tax rate
 * applied to this client's retainer and new fixed-amount / campaign fees.
 *
 * Step M-4.3 — `auto_invoice_retainer` opts the client into recurring retainer draft generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('tax_registration_number')->nullable()->after('billing_address');
            // Falls back to the organization's default_currency when null.
            $table->string('billing_currency', 3)->nullable()->after('tax_registration_number');
            $table->foreignId('default_tax_rate_id')->nullable()->after('billing_currency')->constrained('tax_rates')->nullOnDelete();
            // Step M-4.3: opt-in to recurring retainer drafts.
            $table->boolean('auto_invoice_retainer')->default(false)->after('default_tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_tax_rate_id');
            $table->dropColumn(['tax_registration_number', 'billing_currency', 'auto_invoice_retainer']);
        });
    }
};
