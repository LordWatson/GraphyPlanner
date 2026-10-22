<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — the agency's own tax registration: a number (UK VAT reg / UAE TRN) and the label to
 * print it under. When a number is present the invoice reads "Tax invoice" rather than "Invoice".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tax_registration_number')->nullable()->after('payment_instructions');
            $table->string('tax_registration_label')->nullable()->after('tax_registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['tax_registration_number', 'tax_registration_label']);
        });
    }
};
