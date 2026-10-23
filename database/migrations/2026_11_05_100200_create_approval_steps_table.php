<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.3 — one required approval per post, built from the client's approver contacts and
     * approval policy when the post enters `waiting_client`. Tracks each approver's decision, who
     * made it, when, and the exact content revision they approved (for the approval certificate).
     */
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            // Null when the client has no named approver contacts (legacy): a single generic step
            // that any reviewer / the review-link token satisfies.
            $table->foreignId('client_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('decision', 20)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('approved_revision_id')->nullable()->constrained('post_revisions')->nullOnDelete();
            $table->timestamps();

            $table->index(['post_id', 'position']);
            $table->index('org_id');
            $table->index('client_contact_id');
            $table->index('decided_by_user_id');
            $table->index('approved_revision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
