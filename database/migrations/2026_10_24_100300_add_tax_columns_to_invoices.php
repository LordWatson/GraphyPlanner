<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — tax on invoices. `amount` stays the grand total due (= subtotal + tax_total) so the
 * ledger, outstanding totals and dashboard chart are unchanged; `subtotal` holds the net, and
 * `tax_breakdown` is the per-rate summary ([{name, rate, net, tax}]) printed on the PDF. A
 * fixed-amount invoice (no line items) carries an invoice-level `tax_rate_id`; the retainer's rate
 * is snapshotted at finalize so a later rate change never alters a locked invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->nullable()->after('amount');
            $table->decimal('tax_total', 10, 2)->default(0)->after('subtotal');
            $table->json('tax_breakdown')->nullable()->after('tax_total');
            $table->foreignId('tax_rate_id')->nullable()->after('tax_breakdown')->constrained('tax_rates')->nullOnDelete();
            $table->decimal('retainer_tax_rate', 5, 2)->nullable()->after('retainer_amount_snapshot');
            $table->string('retainer_tax_name')->nullable()->after('retainer_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn(['subtotal', 'tax_total', 'tax_breakdown', 'retainer_tax_rate', 'retainer_tax_name']);
        });
    }
};
