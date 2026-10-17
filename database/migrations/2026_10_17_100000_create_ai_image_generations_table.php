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
        // Step 16.3: one row per "Generate" click in the AI Image Studio.
        Schema::create('ai_image_generations', function (Blueprint $table) {
            $table->id();
            // Doubles as the generated assets' `variant_group_id` (Step 16.2).
            $table->uuid('uuid')->unique();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('ai_post_idea_id')->nullable()->constrained('ai_post_ideas')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // Client-generated id per Generate click: a double submit resolves to the same set.
            $table->uuid('request_id')->unique();

            // Null until the art-direction brief is drafted on the queue (Step 16.5).
            $table->json('brief')->nullable();
            // The art-direction fields a person typed in the studio, re-applied on "Generate again".
            $table->json('art_direction')->nullable();
            $table->string('brief_hash', 64)->nullable();

            $table->string('provider');
            $table->string('model');
            $table->string('quality_tier');
            $table->string('aspect_ratio');
            $table->unsignedTinyInteger('variant_count');
            $table->string('status');
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_image_generations');
    }
};
