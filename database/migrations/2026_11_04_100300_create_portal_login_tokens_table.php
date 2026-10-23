<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.2 — a passwordless "magic link" that signs a client reviewer straight into their
     * portal, landing on a specific post. Only the SHA-256 of the token is stored (the review-token
     * rules); the plain token is shown/emailed once and is single-use (`used_at`) and expiring.
     */
    public function up(): void
    {
        Schema::create('portal_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // The post to land on after sign-in (nullable → the portal dashboard).
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_login_tokens');
    }
};
