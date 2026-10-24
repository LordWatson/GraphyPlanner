<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase M-9 — the social inbox.
 *
 * - `inbox_conversations`: one thread per root comment on a published post target. The thread, not a
 *   single comment, is what gets assigned, SLA'd and resolved — so assignment/sentiment/status live
 *   here, rolled up from the comments. Unique on (social_account_id, external_conversation_id) so the
 *   hourly sync upserts idempotently.
 * - `inbox_messages`: each comment/reply in a thread (inbound from the public, outbound from the
 *   studio). Append-only, upserted by (inbox_conversation_id, external_message_id). Outbound replies
 *   carry who sent them and a delivery status so a failed vendor send can be retried.
 * - `saved_replies`: per-org canned responses, optionally scoped to one client.
 * - `organizations.inbox_sla_hours`: the inbox's own reply SLA (independent of the approval SLA).
 *
 * Comments come from Upload-Post for seven networks (M-0.2); Pinterest and Google Business are
 * `comments => false` in config/platforms.php and never ingest here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            // Nullable: a comment can arrive for a post whose target row was later pruned.
            $table->foreignId('post_target_id')->nullable()->constrained('post_targets')->nullOnDelete();
            $table->string('platform', 32);
            $table->string('external_conversation_id', 191);
            $table->string('author_name')->nullable();
            $table->string('author_handle')->nullable();
            $table->string('author_avatar_url', 2048)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->string('status', 16)->default('open');
            $table->string('sentiment', 16)->default('neutral');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_reply_needed')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            // Throttles the overdue-reply reminder to once per 24h per thread.
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['social_account_id', 'external_conversation_id'], 'inbox_conv_account_external_unique');
            $table->index(['org_id', 'status', 'last_message_at']);
            $table->index(['org_id', 'is_reply_needed', 'status']);
            $table->index(['client_id', 'status']);
            // Each remaining FK column must also lead an index (IndexCoverageTest / Step I-2.1).
            $table->index(['assigned_to', 'status']);
            $table->index('post_target_id');
            $table->index('resolved_by');
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('inbox_conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->string('external_message_id', 191)->nullable();
            $table->string('parent_external_id', 191)->nullable();
            $table->string('direction', 16);
            $table->string('author_name')->nullable();
            $table->string('author_handle')->nullable();
            $table->text('body');
            $table->string('sentiment', 16)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor_comment_id', 191)->nullable();
            $table->string('delivery_status', 16)->nullable();
            $table->string('delivery_error', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['inbox_conversation_id', 'external_message_id'], 'inbox_msg_conv_external_unique');
            $table->index(['inbox_conversation_id', 'posted_at']);
            $table->index(['org_id', 'direction']);
            $table->index('sent_by');
        });

        Schema::create('saved_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            // Null = available to every client in the org; set = scoped to one client.
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('shortcut', 64)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'client_id']);
            $table->index('client_id');
            $table->index('created_by');
        });

        Schema::table('organizations', function (Blueprint $table) {
            // The inbox's own reply SLA in hours (Owner setting); null = no SLA tracked.
            $table->unsignedSmallInteger('inbox_sla_hours')->nullable()->after('approval_sla_hours');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            // When comments were last pulled for this target, so the sync reads oldest-first and
            // respects the minimum interval (mirrors `metrics_synced_at`).
            $table->timestamp('comments_synced_at')->nullable()->after('metrics_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('comments_synced_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('inbox_sla_hours');
        });

        Schema::dropIfExists('saved_replies');
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_conversations');
    }
};
