<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MCP media (out-of-band batch upload link). MCP tool calls carry no binary channel and a model
     * can't reliably transcribe a real image as base64, so `request_media_upload` mints one of these
     * sessions and hands a human a short-lived browser link (`mcp-upload/{token}`) to drop the files.
     * One session covers one client and a fixed set of its posts, and accepts many files across
     * multiple uploads until it expires ("create 3 posts, I'll upload media for each"). Only the
     * token's SHA-256 is stored (the connect-invite / review-token rules).
     */
    public function up(): void
    {
        Schema::create('mcp_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // The posts this session may attach uploads to (all belonging to the client); [] = library-only.
            $table->json('post_ids')->nullable();
            // Every asset created through this session, for the poll tool and the library case.
            $table->json('uploaded_asset_ids')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'expires_at']);
            $table->index('org_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_upload_sessions');
    }
};
