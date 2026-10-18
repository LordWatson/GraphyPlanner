<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 17.6: an organization's saved analytics views, re-run on demand through
     * `GetOrganizationAnalyticsSummaryAction` with the *current* viewer's permissions. Visible to
     * the whole org. Step 17.8's email schedule (`schedule`, `recipient_ids`, `last_sent_at`) is
     * part of the same table since both shipped together.
     */
    public function up(): void
    {
        Schema::create('organization_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('filters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            // null | weekly | monthly (Step 17.8).
            $table->string('schedule')->nullable();
            $table->json('recipient_ids')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'updated_at']);
            $table->index(['schedule', 'last_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_reports');
    }
};
