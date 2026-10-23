<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-8.4 — a creator/business media kit: one per primary brand. The public link is **opt-in**
 * (no `share_token` until the owner turns it on) and **revocable** (clearing the token kills every
 * link). The page only ever renders already-public data, so the token is a random public slug, not
 * a secret — stored in plaintext so the owner can re-copy the link any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_kits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->index('org_id');
            $table->text('bio')->nullable();
            $table->string('share_token')->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_kits');
    }
};
