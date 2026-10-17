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
        // Step 16.4: Brand Brain visual references (logo / style / product) for the Image Studio.
        Schema::create('brand_brain_reference_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_brain_id')->constrained('brand_brains')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('role');
            $table->unsignedSmallInteger('position')->default(0);

            // A ≤1024px WebP copy made once on save, so generation never re-processes originals.
            $table->string('normalized_disk')->nullable();
            $table->string('normalized_path')->nullable();
            $table->string('normalized_mime_type')->nullable();
            $table->timestamps();

            $table->unique(['brand_brain_id', 'asset_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_brain_reference_assets');
    }
};
