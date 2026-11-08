<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-3.1 — one row per WhatsApp message, so "did my client actually get it?" has an answer.
 *
 * `to_phone` is stored in full because that is the only way to match Twilio's status callback back
 * to the row, and because an agency debugging a delivery needs to know which number was used. It
 * is masked everywhere it is *shown* (W-4.2) and never logged. The body is not stored: the
 * template plus its variables is a better record and does not duplicate client content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
            $table->string('to_phone', 32);
            $table->string('notification_type')->nullable();
            $table->string('template', 64);
            $table->string('provider_message_id')->nullable();
            $table->string('status', 16);
            $table->string('error_code', 32)->nullable();
            $table->string('error_reason')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // The status callback arrives keyed only by Twilio's message id.
            $table->index('provider_message_id');
            // The log UI: this org's recent messages, newest first, filtered by status.
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'status']);
            // `IndexCoverageTest`: each foreign key leads an index of its own.
            $table->index('user_id');
            $table->index('client_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
