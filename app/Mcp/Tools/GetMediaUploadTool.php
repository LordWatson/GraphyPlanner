<?php

namespace App\Mcp\Tools;

use App\Enums\McpScope;
use App\Models\Asset;
use App\Models\McpUploadSession;
use App\Models\Post;
use App\Support\Mcp\McpImagePreview;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP media — poll a `request_media_upload` session to see what the user has uploaded. It returns a
 * text summary (per targeted post, the media now attached; plus any library uploads not tied to a
 * post — all as safe metadata, never a signed URL/path) AND the uploaded images themselves as vision
 * previews, so the assistant can LOOK at the media and write captions from it. Org-scoped to the
 * session; requires `WriteDrafts`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_media_upload')]
#[Title('Get media upload status')]
#[Description('Checks a request_media_upload session by session_id. Returns a summary (status, and the asset ids now on each targeted post / in the library, for create_draft_post or set_post_media) AND shows the uploaded images themselves so you can SEE the media and caption from it. Requires the write:drafts scope.')]
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

        $summary = [
            'session_id' => $session->id,
            'status' => $session->isActive() ? 'open' : 'expired',
            'client_id' => $session->client_id,
            'posts' => $posts,
            'library_asset_ids' => $libraryAssets->pluck('id')->all(),
            'library_assets' => $libraryAssets->map(fn (Asset $asset): array => McpPresenters::asset($asset))->all(),
            'total_uploaded' => count($uploaded),
            'expires_at' => $session->expires_at->toIso8601String(),
            'message' => $session->isActive()
                ? 'Open until it expires — the user can keep uploading. Re-check after they confirm they are done. The uploaded images are shown below so you can caption from them.'
                : 'This session has expired. Create a new one with request_media_upload if more media is needed.',
        ];

        // The machine-readable summary first, then the uploaded images so the assistant can SEE the
        // media and write captions from it — each image preceded by a line naming its asset_id so it
        // can tie an image to the id it then passes to create_draft_post / set_post_media.
        $responses = [Response::text((string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];

        foreach ($this->previewableAssets($org->id, $uploaded) as $asset) {
            $preview = McpImagePreview::for($asset);

            if ($preview === null) {
                continue;
            }

            $label = 'Asset '.$asset->id.($asset->original_filename !== null ? ' ('.$asset->original_filename.')' : '').':';
            $responses[] = Response::text($label);
            $responses[] = Response::image($preview['data'], $preview['mime']);
        }

        return Response::make($responses);
    }

    /**
     * The session's uploaded assets to show as vision previews — newest first, capped so one call can't
     * return an unbounded payload.
     *
     * @param  list<int>  $uploadedIds
     * @return Collection<int, Asset>
     */
    private function previewableAssets(int $orgId, array $uploadedIds): Collection
    {
        if ($uploadedIds === []) {
            return collect();
        }

        return Asset::query()
            ->withoutOrgScope()
            ->where('org_id', $orgId)
            ->whereIn('id', $uploadedIds)
            ->latest('id')
            ->limit(24)
            ->get();
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
