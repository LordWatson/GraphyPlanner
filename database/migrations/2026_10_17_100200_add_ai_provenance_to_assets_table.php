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
        // Step 16.2: provenance for AI-generated assets. The set itself is grouped through the
        // existing `variant_group_id` (= the generation's uuid), so no new grouping column.
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('ai_image_variant_id')->nullable()->after('variant_group_id')
                ->constrained('ai_image_variants')->nullOnDelete();
            $table->boolean('is_ai_generated')->default(false)->after('ai_image_variant_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_image_variant_id');
            $table->dropIndex(['is_ai_generated']);
            $table->dropColumn('is_ai_generated');
        });
    }
};
