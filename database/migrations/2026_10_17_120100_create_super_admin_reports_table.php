<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 15.5: saved super admin report configurations (date range, org ids, metric sections),
     * re-run on demand through `GetPlatformAnalyticsSummaryAction`. Platform-level, so no `org_id`.
     */
    public function up(): void
    {
        Schema::create('super_admin_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('filters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_reports');
    }
};
