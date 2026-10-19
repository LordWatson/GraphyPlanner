<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-2.8 — a link an agency sends a client so they connect their own social accounts,
     * without sharing a password. Only the token's hash is stored (the review-token rules). An
     * invite can be limited to specific account slots or platforms, expires (7 days by default)
     * and can be revoked; `connected_account_ids` records what was connected through it.
     */
    public function up(): void
    {
        Schema::create('connect_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('email')->nullable();
            $table->json('allowed_social_account_ids')->nullable();
            $table->json('platforms')->nullable();
            $table->json('connected_account_ids')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'expires_at']);
            $table->index('org_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_invites');
    }
};
