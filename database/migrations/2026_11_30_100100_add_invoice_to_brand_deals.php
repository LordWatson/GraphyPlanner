<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-1.2 — which invoice a brand deal was billed on. Nullable: most deals are never
 * invoiced through Alima, and `nullOnDelete` means deleting an invoice leaves the deal intact
 * (and invoiceable again) rather than taking the sponsor relationship with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_deals', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('contact_email')
                ->constrained('invoices')->nullOnDelete();

            // `IndexCoverageTest` requires every FK column to lead an index of its own.
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('brand_deals', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
