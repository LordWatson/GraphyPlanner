<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-3.3 / I-10.3 (Phase 8) — the client-facing monthly report.
 *
 * - `client_reports`: one per client per month. `snapshot` holds the client-safe figures (no
 *   billing, own client only, published work), rebuilt while a draft and **frozen** when published,
 *   so the numbers a client saw never change. `commentary` is the agency's own note.
 * - `clients.report_schedule`: `review` (default — a draft is prepared on the 1st for the team to
 *   add commentary and publish), `automatic` (prepared, published and emailed on the 1st) or `off`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft');
            $table->text('commentary')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'period_start']);
            $table->index(['org_id', 'status']);
            $table->index('published_by');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('report_schedule', 16)->default('review');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('report_schedule');
        });

        Schema::dropIfExists('client_reports');
    }
};
