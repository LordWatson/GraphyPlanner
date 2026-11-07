<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Download everything we hold for this organisation" — the other half of
 * `PurgeOrganizationJob`, and the difference between GDPR portability being a product feature and
 * being a support ticket.
 *
 * One row per request, so an owner can see what they asked for, when it finished, and whether it
 * is still downloadable. The file itself lives on the private assets disk and is deleted when the
 * retention window (`config('exports.retention_days')`) passes — the row stays as a receipt that
 * the export happened and was removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            // Where the archive landed. Nullable: a pending or failed export has no file, and an
            // expired one no longer does.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            // Why it failed, in words an owner can act on. Never an exception trace.
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
            // The purge sweep reads by expiry across every organisation.
            $table->index(['status', 'expires_at']);
            // `IndexCoverageTest` requires every foreign key to lead an index.
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
