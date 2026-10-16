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
        Schema::table('clients', function (Blueprint $table) {
            // Where invoices are emailed (falls back to approval_email when unset), and the
            // address printed in the invoice PDF's "Billed to" block.
            $table->string('billing_email')->nullable()->after('approval_email');
            $table->text('billing_address')->nullable()->after('billing_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['billing_email', 'billing_address']);
        });
    }
};
