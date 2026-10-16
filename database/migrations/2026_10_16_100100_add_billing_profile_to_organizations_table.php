<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Printed on every generated invoice PDF: the studio's own address / company / tax
            // details, and how a client should pay (bank details, payment link, ...).
            $table->text('billing_details')->nullable()->after('default_currency');
            $table->text('payment_instructions')->nullable()->after('billing_details');
            // Default payment terms — new invoices without an explicit due date are due this many
            // days after their issue date (0 = due on receipt).
            $table->unsignedSmallInteger('invoice_payment_terms_days')->default(14)->after('payment_instructions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['billing_details', 'payment_instructions', 'invoice_payment_terms_days']);
        });
    }
};
