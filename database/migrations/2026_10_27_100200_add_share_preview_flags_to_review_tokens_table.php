<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-3.4 — "Share preview" reuses the `ReviewToken` machinery for a read-only link a business
 * owner or creator can send to a partner or sponsor. Two opt-in flags widen what the link can do:
 *
 * - `allow_comments` — the viewer may leave a comment (never an internal note).
 * - `allow_approval` — the viewer gets decision buttons (a sponsor sign-off). The decision is
 *   recorded but does **not** change the post's status (a business post has no `waiting_client`
 *   stage); a future "require sponsor approval" per-post flag (B-11.1) will act on it.
 *
 * Both default `false`, so every existing token — and the agency client-review flow — is a
 * view-and-decide-on-`waiting_client` token exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('review_tokens', 'allow_comments')) {
                $table->boolean('allow_comments')->default(false)->after('grid_plan_id');
            }

            if (! Schema::hasColumn('review_tokens', 'allow_approval')) {
                $table->boolean('allow_approval')->default(false)->after('allow_comments');
            }
        });
    }

    public function down(): void
    {
        Schema::table('review_tokens', function (Blueprint $table) {
            $table->dropColumn(['allow_comments', 'allow_approval']);
        });
    }
};
