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
        Schema::table('brand_brains', function (Blueprint $table) {
            $table->string('persona_disk')->nullable()->after('content_pillars');
            $table->string('persona_path')->nullable()->after('persona_disk');
            $table->string('persona_url')->nullable()->after('persona_path');
            $table->string('persona_original_filename')->nullable()->after('persona_url');
            $table->string('persona_mime_type')->nullable()->after('persona_original_filename');
            $table->unsignedBigInteger('persona_size')->nullable()->after('persona_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_brains', function (Blueprint $table) {
            $table->dropColumn([
                'persona_disk',
                'persona_path',
                'persona_url',
                'persona_original_filename',
                'persona_mime_type',
                'persona_size',
            ]);
        });
    }
};
