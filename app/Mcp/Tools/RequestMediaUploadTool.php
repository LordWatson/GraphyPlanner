<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\CreateMcpUploadSessionAction;
use App\Enums\McpScope;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * MCP media — the reliable way to get real images from the user into Graphy. A model can't carry a
 * photo as base64 in a tool argument without corrupting it, so instead of taking bytes this mints a
 * short-lived browser link (reusing the connect-invite pattern via `CreateMcpUploadSessionAction`)
 * that the user opens to drop their files. One link is a batch SESSION: pass the ids of the posts you
 * just created and the user can upload one or many files to each, in one sitting ("create 3 posts,
 * I'll upload media for each"). Omit post_ids to collect media into the client's library. Poll
 * `get_media_upload` for what landed, then chain the asset ids into create_draft_post/set_post_media.
 *
 * MCP never receives or stores the raw token — only the URL is returned (the secrets-guard test
 * asserts this). Requires `WriteDrafts` and the `AssetPolicy::create` (plus `PostPolicy::update` for
 * each targeted post) the web UI enforces.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('request_media_upload')]
#[Title('Request media upload link')]
#[Description('Creates one short-lived browser link the user opens to upload images for a client. Pass post_ids (posts you created, incl. empty placeholders) and they can upload one or many files to each; omit them to collect media into the library. Use this to get media the user must provide BEFORE finalising captions — then call get_media_upload to SEE the images and write captions from them. Returns the upload URL — never a token. Requires the write:drafts scope.')]
class RequestMediaUploadTool extends McpTool
{
    public function __construct(private readonly CreateMcpUploadSessionAction $createSession) {}

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

        $user = $this->actAsOrganizationMember();

        if ($user === null) {
            return Response::error('No member is available to authorize this connection.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'post_ids' => ['nullable', 'array'],
            'post_ids.*' => ['integer', 'distinct'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        if ($user->cannot('create', [Asset::class, $client])) {
            return Response::error('You are not allowed to upload media for this client.');
        }

        $postIds = array_values(array_map('intval', $validated['post_ids'] ?? []));
        $posts = [];

        if ($postIds !== []) {
            $posts = Post::query()
                ->withoutOrgScope()
                ->where('org_id', $org->id)
                ->where('client_id', $client->id)
                ->whereIn('id', $postIds)
                ->get();

            if ($posts->count() !== count($postIds)) {
                return Response::error('One or more post ids do not exist for this client.');
            }

            foreach ($posts as $post) {
                if ($user->cannot('update', $post)) {
                    return Response::error('You are not allowed to edit post '.$post->id.'.');
                }
            }
        }

        $expiresAt = isset($validated['expires_in_minutes'])
            ? now()->addMinutes((int) $validated['expires_in_minutes'])
            : null;

        $result = ($this->createSession)($client, $user, $postIds, $expiresAt);

        // Return the human-completable URL only — never the raw token ($result['token']).
        return Response::structured([
            'session_id' => $result['session']->id,
            'client_id' => $client->id,
            'post_ids' => $postIds,
            'upload_url' => $result['url'],
            'expires_at' => $result['session']->expires_at->toIso8601String(),
            'message' => $postIds !== []
                ? 'Send this one link to the user. The page shows each post so they can upload one or more images to each. Then call get_media_upload with session_id to see what landed on each post.'
                : 'Send this one link to the user to upload images into the client library, then call get_media_upload with session_id to get the asset ids for create_draft_post or set_post_media.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client the media is for.'),
            'post_ids' => $schema->array()->description('The posts the user will upload media for (each must belong to the client). The link shows one upload area per post; each accepts multiple files. Omit to collect media into the library instead.'),
            'expires_in_minutes' => $schema->integer()->description('How long the link stays valid (5–240; default 60).'),
        ];
    }
}
