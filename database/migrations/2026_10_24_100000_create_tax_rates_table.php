<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — a per-organization list of tax rates (e.g. UK VAT 20%, UAE VAT 5%) that invoice
 * line items and the retainer pick from, rather than hard-coded values. A rate is archived rather
 * than deleted once invoices snapshot it, so historical invoices keep their recorded rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            // A percentage, e.g. 20.00, 5.00, 0.00.
            $table->decimal('rate', 5, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
