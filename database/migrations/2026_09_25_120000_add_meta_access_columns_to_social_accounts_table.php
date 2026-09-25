<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 1.9.2: a Meta (Facebook Login for Business) user access token, its expiry, and the
     * connected Instagram Business user id — needed only for the Instagram audio-search Graph
     * API call (`GET /ig_audio`, Step 1.9.4). Only ever populated for Instagram accounts, via the
     * additive Facebook Login connect flow (Step 1.9.3); it does not replace/affect how accounts
     * are connected for publishing via Upload-Post.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('meta_access_token')->nullable()->after('external_account_id');
            $table->timestamp('meta_access_token_expires_at')->nullable()->after('meta_access_token');
            $table->string('meta_instagram_user_id')->nullable()->after('meta_access_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['meta_access_token', 'meta_access_token_expires_at', 'meta_instagram_user_id']);
        });
    }
};
