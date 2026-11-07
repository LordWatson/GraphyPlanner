<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase GTM-2 (closes CG-6.1–6.3) — the creator's own email list.
 *
 * `audience_contacts` is one person per brand (`client_id` + `email` unique, so a second capture
 * finds the existing row rather than making a duplicate). Consent and suppression are separate
 * columns on purpose: `consent_at` is only ever set by the person themselves (a confirmation link
 * or an explicit checkout opt-in), and `unsubscribed_at` outlives everything — an import or a new
 * purchase must never bring a contact back.
 *
 * `audience_sends` is the idempotency record for the automated triggers. The unique index is the
 * lock: a send that is already recorded can't be recorded again, so a retried job or a replayed
 * webhook can't email the same person twice. `reference` is `''` rather than null for the triggers
 * that aren't tied to a row, because both MySQL and SQLite treat NULLs in a unique index as
 * distinct and would let duplicates through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('source', 16);
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'email']);
            $table->index('org_id');
            $table->index(['client_id', 'consent_at']);
        });

        Schema::create('audience_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('audience_contact_id')->constrained('audience_contacts')->cascadeOnDelete();
            $table->string('trigger', 24);
            $table->string('reference', 32)->default('');
            $table->timestamps();

            $table->unique(['audience_contact_id', 'trigger', 'reference']);
            $table->index('org_id');
        });

        Schema::create('audience_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 180);
            $table->text('body');
            $table->string('segment', 16);
            // Only used by the `product` segment; the product going away widens the segment to
            // nothing rather than silently mailing everyone.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();

            $table->index('org_id');
            $table->index('created_by');
            $table->index('product_id');
            $table->index(['client_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_broadcasts');
        Schema::dropIfExists('audience_sends');
        Schema::dropIfExists('audience_contacts');
    }
};
