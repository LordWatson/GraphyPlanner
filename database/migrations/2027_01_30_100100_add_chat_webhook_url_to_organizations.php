<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team-chat delivery for the moments an agency reacts to — a client approving, requesting changes,
 * or a publish failing. One incoming-webhook URL per organization, stored encrypted like the
 * vendor keys beside it.
 *
 * `ChatNotifier` has existed since Step M-6.7 with only a no-op implementation, so every caller
 * that thought it was telling the team something was talking to nothing. The agency research is
 * blunt about the cost of that: approvals leak into Slack and email, and the tool's own approval
 * state becomes a formality updated afterwards — which makes the data wrong and the product
 * optional. A webhook URL is the smallest thing that puts the message where the work already is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('chat_webhook_url')->nullable()->after('upload_post_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('chat_webhook_url');
        });
    }
};
