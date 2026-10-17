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
        // Step 16.3: one tile in a set, including refine/regenerate/final children.
        Schema::create('ai_image_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained('ai_image_generations')->cascadeOnDelete();
            // Denormalised from the generation so the monthly quota count is one indexed query.
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind');
            $table->string('status');
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('parent_variant_id')->nullable()->constrained('ai_image_variants')->nullOnDelete();
            $table->string('quality_tier');

            // Never pay twice: one vendor call per key, ever (Step 16.7).
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('seed')->nullable();
            // The plain-words refine instruction a person typed (internal-only, never shown to clients).
            $table->string('instruction', 300)->nullable();

            // Lightweight WebP preview for the studio grid; the asset keeps the vendor original.
            $table->string('preview_disk')->nullable();
            $table->string('preview_path')->nullable();

            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->timestamp('selected_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
            $table->index(['generation_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_image_variants');
    }
};
