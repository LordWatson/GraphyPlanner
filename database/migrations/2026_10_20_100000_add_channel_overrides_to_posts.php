<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-3.1 / M-2.2 — per-platform captions, first comment, link and alt text.
     *
     * The post keeps the master values (caption, hashtags, and now a link and a first comment);
     * each target can override any of them. `null` on a target means "use the master"; an empty
     * string / empty list means "none on this channel". Alt text is per image (`post_assets`) and
     * goes to every platform that accepts it.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('link_url', 2048)->nullable()->after('hashtags');
            $table->text('first_comment')->nullable()->after('link_url');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->text('caption_override')->nullable()->after('scheduled_at_utc');
            $table->json('hashtags_override')->nullable()->after('caption_override');
            $table->text('first_comment')->nullable()->after('hashtags_override');
            $table->string('link_url', 2048)->nullable()->after('first_comment');
        });

        Schema::table('post_assets', function (Blueprint $table) {
            $table->text('alt_text')->nullable()->after('crop_zoom');
        });
    }

    public function down(): void
    {
        Schema::table('post_assets', function (Blueprint $table) {
            $table->dropColumn('alt_text');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['caption_override', 'hashtags_override', 'first_comment', 'link_url']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['link_url', 'first_comment']);
        });
    }
};
