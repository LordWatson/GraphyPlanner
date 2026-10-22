<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — each line item carries an optional tax rate. `tax_rate_id` links to the live rate
 * for editing; `tax_rate` / `tax_name` snapshot the chosen rate at write time so archiving or
 * editing the rate later never alters an item that's already been billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('quantity')->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_rate_id');
            $table->string('tax_name')->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn(['tax_rate', 'tax_name']);
        });
    }
};
