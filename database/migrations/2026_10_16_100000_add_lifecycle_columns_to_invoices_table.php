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
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('retainer_amount_snapshot');
            // Who the invoice email actually went to when it was sent (null = marked sent
            // without emailing, e.g. delivered by hand).
            $table->string('sent_to_email')->nullable()->after('sent_at');
            $table->timestamp('last_reminded_at')->nullable()->after('sent_to_email');
            $table->unsignedInteger('reminder_count')->default(0)->after('last_reminded_at');
            $table->string('payment_reference')->nullable()->after('paid_at');
            $table->timestamp('voided_at')->nullable()->after('payment_reference');
            $table->text('void_reason')->nullable()->after('voided_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'finalized_at', 'sent_to_email', 'last_reminded_at', 'reminder_count',
                'payment_reference', 'voided_at', 'void_reason',
            ]);
        });
    }
};
