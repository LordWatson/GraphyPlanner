<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-7.2 (dev plan I-9.2) — async AI with progress. Captions, trends and ideas used to run
     * inside the HTTP request (vendor latency + a schema retry), risking a gateway timeout. They now
     * dispatch a queued job keyed by this row; the client polls it and can cancel. The row holds the
     * finished payload (the same shape the sync endpoint used to return) so the poll is a cheap read.
     */
    public function up(): void
    {
        Schema::create('ai_text_jobs', function (Blueprint $table) {
            // Client-supplied uuid (idempotent dispatch) — the request_id the UI polls.
            $table->uuid('id')->primary();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->string('kind'); // captions | trends | ideas
            $table->json('params')->nullable();
            $table->string('status')->default('queued'); // queued | running | completed | failed | cancelled
            $table->json('result')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
            // Every FK column leads an index (IndexCoverageTest); org_id is covered above.
            $table->index('user_id');
            $table->index('client_id');
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_text_jobs');
    }
};
