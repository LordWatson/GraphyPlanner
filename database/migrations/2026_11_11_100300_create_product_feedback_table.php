<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-8.2 — in-app "Send feedback" from a partner org's staff. One row per submission, scoped
     * to the sender's organization. `page` records where they were when they sent it (route/URL), so
     * super admin can see which screen prompted the note. No PII beyond `user_id`, and the body is a
     * free-text product comment — never a secret. FK columns lead an index (see `IndexCoverageTest`).
     */
    public function up(): void
    {
        Schema::create('product_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('page', 255)->nullable();
            $table->text('message');
            $table->timestamps();

            $table->index('org_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feedback');
    }
};
