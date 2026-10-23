<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-5.1 — managed publishing credentials.
 *
 * - `publish_mode`: a per-org override of the type-based default (`byo` for agencies, `managed`
 *   for business workspaces). Null means "use the default", so every existing org is unchanged.
 * - `upload_post_profile`: the org's single isolated Upload-Post profile username in managed mode,
 *   assigned lazily by `EnsureManagedPublishProfileAction`. Not a secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('publish_mode')->nullable()->after('upload_post_webhook_secret');
            $table->string('upload_post_profile')->nullable()->after('publish_mode');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['publish_mode', 'upload_post_profile']);
        });
    }
};
