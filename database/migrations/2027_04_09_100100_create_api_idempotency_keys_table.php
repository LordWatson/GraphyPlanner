<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 18 step 2 — what makes a write to the public API safe to retry.
 *
 * A client that times out cannot tell whether we created the post. Without a record of the key it
 * sent, its retry creates a second one. With this table, the retry gets the first response back.
 *
 * The row is written **before** the request runs, not after, so two concurrent retries of the same
 * key collide on the unique index instead of both doing the work — the same ordering lesson as the
 * Connect webhook's claim row (audit finding 2) and `WebhookDispatcher`'s delivery row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key');
            // Which endpoint the key was used on. The same key on a different endpoint is a
            // different operation, and treating it as a replay would return the wrong body.
            $table->string('endpoint');
            // SHA-256 of the request payload. A retry must carry the same body; a *different* body
            // under a key we have already seen is a client bug, and answering it with the old
            // response would hide that.
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'key']);
            // Pruning: `api:prune-idempotency-keys` deletes rows past the retention window.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
