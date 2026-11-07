<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-3.2 — a creator's own hostname for their bio page. `hostname` is **globally** unique:
 * two pages cannot claim the same name, and an unverified claim doesn't reserve it either (it is
 * released by deleting the row).
 *
 * `verified_at` is the only thing that makes the hostname serve anything, and the nightly
 * re-verification clears it the moment the CNAME stops pointing here — a domain that has been
 * repointed must stop resolving to somebody else's page immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bio_page_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bio_page_id')->constrained('bio_pages')->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            // Maintained by whatever terminates TLS in front of the app, not by Alima: we cannot
            // issue a certificate. See `ops.md`.
            $table->string('ssl_status', 16)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();

            $table->index('bio_page_id');
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_page_domains');
    }
};
