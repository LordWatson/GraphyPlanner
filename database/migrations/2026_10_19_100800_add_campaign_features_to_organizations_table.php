<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns enhancement C-9.4 — per-organization overrides for the gated campaign features
 * (`{"templates": false, ...}`). Null means "use the deployment defaults".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('campaign_features')->nullable()->after('ai_monthly_image_quota');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('campaign_features');
        });
    }
};
