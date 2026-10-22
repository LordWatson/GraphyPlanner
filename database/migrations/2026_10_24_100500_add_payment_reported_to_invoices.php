<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.2 — the payment-confirmation handshake. When a client reports (from the portal) that
 * they've paid, the invoice moves `sent → pending_confirmation` and records what they said here,
 * kept distinct from the org's own confirmed `payment_reference`. The org then confirms
 * (`→ paid`) or reverts (`→ sent`). `status` is a plain string column, so the new
 * `pending_confirmation` value needs no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('payment_reported_at')->nullable()->after('payment_reference');
            $table->string('payment_reported_method')->nullable()->after('payment_reported_at');
            $table->string('payment_reported_reference')->nullable()->after('payment_reported_method');
            $table->text('payment_reported_note')->nullable()->after('payment_reported_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'payment_reported_at',
                'payment_reported_method',
                'payment_reported_reference',
                'payment_reported_note',
            ]);
        });
    }
};
