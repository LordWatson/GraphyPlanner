<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 16.11 (built here ahead of Step 2.8, which should reuse it): one row per AI vendor
        // call, success or failure, with its cost estimate.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('model');
            // e.g. image.generate | image.edit (Step 2.8 adds the text methods).
            $table->string('method');
            $table->boolean('succeeded');
            $table->string('error_code')->nullable();
            $table->string('quality_tier')->nullable();
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('ai_image_variant_id')->nullable()->constrained('ai_image_variants')->nullOnDelete();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            // Null = unlimited (Step 16.11).
            $table->unsignedInteger('ai_monthly_image_quota')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_image_quota');
        });

        Schema::dropIfExists('ai_usage_logs');
    }
};
