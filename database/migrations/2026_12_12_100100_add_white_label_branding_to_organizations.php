<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-3.1 — per-org white-label branding, on `organizations` rather than a new model (the
 * table already carries the org's other identity settings). Every column is nullable: null means
 * "use Alima's own chrome", which is what every existing org gets and what a plan without
 * `Feature::WhiteLabel` always resolves to.
 *
 * The logo lives on the configured `AssetStorage` disk (private by default) and is reached through
 * a signed URL, so an agency's artwork isn't at a permanent guessable address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('brand_logo_disk')->nullable()->after('name');
            $table->string('brand_logo_path')->nullable()->after('brand_logo_disk');
            // A 7-character hex colour (`#RRGGBB`), validated on the way in.
            $table->string('brand_accent', 7)->nullable()->after('brand_logo_path');
            $table->string('brand_sender_name', 120)->nullable()->after('brand_accent');
            $table->string('brand_reply_to')->nullable()->after('brand_sender_name');
            $table->string('brand_footer', 300)->nullable()->after('brand_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'brand_logo_disk', 'brand_logo_path', 'brand_accent',
                'brand_sender_name', 'brand_reply_to', 'brand_footer',
            ]);
        });
    }
};
