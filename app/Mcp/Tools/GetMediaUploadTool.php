<?php

namespace App\Mcp\Tools;

use App\Enums\McpScope;
use App\Models\Asset;
use App\Models\McpUploadSession;
use App\Models\Post;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP media — poll a `request_media_upload` session to see what the user has uploaded. Reports, per
 * targeted post, the media now attached (so the assistant can confirm "post 102 has 2 images"), plus
 * any library uploads not tied to a post (so it can chain their ids into create_draft_post). Returns
 * only safe asset metadata (never a signed URL/path), org-scoped to the session. Requires the
 * `WriteDrafts` scope (it's part of the media-write workflow).
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_media_upload')]
#[Title('Get media upload status')]
#[Description('Checks a request_media_upload session by session_id. Returns status (open or expired), the media now on each targeted post, and any library uploads not tied to a post — with asset ids to use in create_draft_post or set_post_media. Requires the write:drafts scope.')]
#[IsReadOnly]
class GetMediaUploadTool extends McpTool
{
    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::WriteDrafts];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        $this->actAsOrganizationMember();

        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
        ]);

        $session = McpUploadSession::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['session_id'])
            ->first();

        if ($session === null) {
            return Response::error('No upload session with that id exists in this organization.');
        }

        $uploaded = $session->uploaded_asset_ids ?? [];
        $attachedToPosts = [];
        $posts = [];

        foreach ($session->post_ids ?? [] as $postId) {
            $post = Post::query()
                ->withoutOrgScope()
                ->where('org_id', $org->id)
                ->where('id', $postId)
                ->with('assets')
                ->first();

            if ($post === null) {
                continue;
            }

            $assetIds = $post->assets->pluck('id')->map(fn ($id) => (int) $id)->all();
            $attachedToPosts = [...$attachedToPosts, ...$assetIds];

            $posts[] = [
                'post_id' => $post->id,
                'count' => count($assetIds),
                'assets' => $post->assets->map(fn (Asset $asset): array => McpPresenters::asset($asset))->all(),
            ];
        }

        // Library uploads = session uploads not attached to any of the session's posts.
        $libraryIds = array_values(array_diff($uploaded, $attachedToPosts));
        $libraryAssets = Asset::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->whereIn('id', $libraryIds)
            ->get();

        return Response::structured([
            'session_id' => $session->id,
            'status' => $session->isActive() ? 'open' : 'expired',
            'client_id' => $session->client_id,
            'posts' => $posts,
            'library_asset_ids' => $libraryAssets->pluck('id')->all(),
            'library_assets' => $libraryAssets->map(fn (Asset $asset): array => McpPresenters::asset($asset))->all(),
            'total_uploaded' => count($uploaded),
            'expires_at' => $session->expires_at->toIso8601String(),
            'message' => $session->isActive()
                ? 'Open until it expires — the user can keep uploading. Re-check after they confirm they are done.'
                : 'This session has expired. Create a new one with request_media_upload if more media is needed.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->integer()->required()->description('The session_id returned by request_media_upload.'),
        ];
    }
}
