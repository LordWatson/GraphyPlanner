<?php

namespace App\Actions\Mcp;

use App\Models\Client;
use App\Models\McpUploadSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MCP media — mint a short-lived browser link a human opens to finish media uploads for the MCP
 * assistant (the `request_media_upload` tool). One session covers one client and a fixed set of its
 * posts and accepts many files until it expires. Mirrors `CreateConnectInviteAction`: only the
 * token's SHA-256 is stored; the raw token is returned once (in the URL) and never logged. A single
 * insert, so no transaction (CLAUDE.md #5).
 */
class CreateMcpUploadSessionAction
{
    /**
     * @param  list<int>  $postIds  posts this session may attach to (all belong to $client); [] = library-only
     * @return array{session: McpUploadSession, token: string, url: string}
     */
    public function __invoke(Client $client, User $creator, array $postIds = [], ?CarbonInterface $expiresAt = null): array
    {
        $token = Str::random(64);

        $session = McpUploadSession::query()->create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'token_hash' => McpUploadSession::hashToken($token),
            'post_ids' => array_values(array_unique($postIds)),
            'uploaded_asset_ids' => [],
            'expires_at' => $expiresAt ?? now()->addMinutes(McpUploadSession::DEFAULT_TTL_MINUTES),
            'created_by' => $creator->id,
        ]);

        $url = route('mcp-upload.show', $token);

        Log::info('MCP upload session created', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'mcp_upload_session_id' => $session->id,
            'post_count' => count($postIds),
            'user_id' => $creator->id,
        ]);

        return ['session' => $session, 'token' => $token, 'url' => $url];
    }
}
