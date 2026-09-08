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
        Schema::create('brand_brains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->json('voice')->nullable();
            $table->json('audience')->nullable();
            $table->json('offer')->nullable();
            $table->json('visual')->nullable();
            $table->json('music_policy')->nullable();
            $table->json('hashtag_policy')->nullable();
            $table->json('content_pillars')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_brains');
    }
};
