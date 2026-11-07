<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses we must stop sending to: hard bounces and spam complaints, reported by the mail
 * provider's webhook.
 *
 * Deliverability is shared. One bad address kept in a marketing list damages the reputation of the
 * domain that also carries approval requests, invitations and invoices — and the agency research
 * named email landing in spam as the single biggest cause of approval delay. Continuing to send to
 * an address that has already bounced or complained is the fastest way to earn that.
 *
 * Platform-wide rather than per-organization on purpose: the sending reputation being protected is
 * the platform's, and an address that complained about one agency's mail has not consented to
 * another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressed_emails', function (Blueprint $table) {
            $table->id();
            // Lower-cased by the application before it reaches here, so the unique index is the
            // real guard against duplicates rather than a case-sensitive near-miss.
            $table->string('email')->unique();
            $table->string('reason', 32);
            $table->string('source', 32)->nullable();
            $table->timestamps();

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_emails');
    }
};
