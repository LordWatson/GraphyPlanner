<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-3.5 / M-2.4 — what `ProbeAssetJob` learns about an upload (width/height already
     * exist): video duration and codec, a small thumbnail for lists and grids, and when it ran.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('height');
            $table->string('video_codec', 32)->nullable()->after('duration_ms');
            $table->string('thumbnail_path')->nullable()->after('video_codec');
            $table->timestamp('probed_at')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'video_codec', 'thumbnail_path', 'probed_at']);
        });
    }
};
