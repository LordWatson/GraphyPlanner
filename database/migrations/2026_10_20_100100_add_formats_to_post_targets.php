<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-3.2 / M-2.3 — an explicit format per target (feed / reel / story / carousel / short,
     * from `config/platforms.php`; null = "Auto", inferred from the media as before), the order of
     * attached media (carousel slides), and a cover image + frame offset for video.
     */
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->string('format', 32)->nullable()->after('link_url');
        });

        Schema::table('post_assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('asset_id');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('cover_asset_id')->nullable()->after('media_format')->constrained('assets')->nullOnDelete();
            $table->unsignedInteger('thumb_offset_ms')->nullable()->after('cover_asset_id');
            $table->index('cover_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_asset_id');
            $table->dropColumn('thumb_offset_ms');
        });

        Schema::table('post_assets', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
