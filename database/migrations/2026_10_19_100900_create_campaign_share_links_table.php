<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns enhancement C-5.4 — read-only, expiring, revocable preview links for stakeholders
 * without portal access. The token is hashed at rest (SHA-256), like `review_tokens`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_share_links');
    }
};
