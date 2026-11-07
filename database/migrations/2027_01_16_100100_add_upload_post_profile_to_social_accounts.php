<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix — Upload-Post bills by *profile*, and a profile holds one connected account per platform.
 * Until now every `SocialAccount` resolved to its own profile (byo) or the whole org shared one
 * (managed), so a client with five networks burned five profiles off the plan. The profile now
 * lives on the account and is shared by every account of the same client that needs a different
 * platform slot — see `App\Actions\SocialAccounts\EnsurePublishProfileAction`.
 *
 * The backfill writes each already-connected account's *current* vendor profile, so no live link
 * is orphaned by this change. Those accounts stay where they are until they are reconnected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('upload_post_profile')->nullable()->after('external_account_id');
        });

        $organizations = DB::table('organizations')
            ->get(['id', 'type', 'publish_mode', 'upload_post_profile'])
            ->keyBy('id');

        DB::table('social_accounts')
            ->where('connection_status', '!=', 'not_connected')
            ->chunkById(200, function ($accounts) use ($organizations) {
                foreach ($accounts as $account) {
                    $organization = $organizations[$account->org_id] ?? null;

                    $managed = $organization !== null && ($organization->publish_mode === 'managed'
                        || ($organization->publish_mode === null && $organization->type === 'business'));

                    // Exactly what `profileFor()` returned before this change, per mode.
                    $profile = $managed
                        ? $organization->upload_post_profile
                        : ($account->external_account_id ?? $account->external_profile_id ?? (string) $account->id);

                    if (filled($profile)) {
                        DB::table('social_accounts')
                            ->where('id', $account->id)
                            ->update(['upload_post_profile' => $profile]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('upload_post_profile');
        });
    }
};
