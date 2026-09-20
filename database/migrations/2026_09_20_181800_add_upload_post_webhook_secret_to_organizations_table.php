<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1.5 (revised): Upload-Post issues one webhook signing secret per account (returned
     * from `POST /api/uploadposts/users/notifications` when a webhook URL is registered), not a
     * single app-wide secret — so it belongs next to `upload_post_key`, matched per organization.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('upload_post_webhook_secret')->nullable()->after('upload_post_key');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('upload_post_webhook_secret');
        });
    }
};
