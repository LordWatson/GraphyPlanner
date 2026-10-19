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
        // Per-channel image format (`{"instagram": "4:5", ...}`, see App\Enums\MediaRatio). Null
        // or a missing key means the channel's default (`original`).
        Schema::table('posts', function (Blueprint $table) {
            $table->json('media_format')->nullable()->after('location');
        });

        // Each attached image's framing: the focus point (0–1 from the top-left) a fixed-ratio
        // crop centres on, and how far it zooms in (1 = the largest box of that ratio).
        Schema::table('post_assets', function (Blueprint $table) {
            $table->decimal('crop_x', 5, 4)->default(0.5)->after('asset_id');
            $table->decimal('crop_y', 5, 4)->default(0.5)->after('crop_x');
            $table->decimal('crop_zoom', 4, 2)->default(1)->after('crop_y');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_assets', function (Blueprint $table) {
            $table->dropColumn(['crop_x', 'crop_y', 'crop_zoom']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('media_format');
        });
    }
};
