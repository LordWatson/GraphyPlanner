<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\UpdatePostAction;
use App\Enums\McpScope;
use App\Models\Asset;
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
 * Phase MCP — set the media attached to a post, so the user can say "replace the media on this post
 * with this image" (upload_media → set_post_media mode=replace) or add/remove specific assets. It
 * reuses the exact `UpdatePostAction` the web editor calls, so the carousel order (`post_assets`
 * sort_order), the §6 checklist refresh, revision history and the vendor recall/resubmit for a
 * scheduled post all apply unchanged. It never changes the post's status — a human still reviews it.
 *
 * Requires the `WriteDrafts` scope and the `update` policy. Every supplied asset must belong to the
 * post's own client in this organization — an id from another client/org is refused.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('set_post_media')]
#[Title('Set post media')]
#[Description('Sets the media attached to a post from asset ids (use upload_media first to add a new image). mode=replace swaps all media (the default), add appends, remove detaches. Reuses the web editor\'s media sync and checklist; never changes the post status. Requires the write:drafts scope.')]
class SetPostMediaTool extends McpTool
{
    public function __construct(private readonly UpdatePostAction $updatePost) {}

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

        $post = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', (int) $request->get('post_id'))
            ->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this organization.');
        }

        if ($user->cannot('update', $post)) {
            return Response::error('You are not allowed to edit this post.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'integer'],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'distinct'],
            'mode' => ['nullable', 'string', 'in:replace,add,remove'],
        ]);

        $mode = $validated['mode'] ?? 'replace';
        $requested = array_values(array_map('intval', $validated['asset_ids']));

        // Every supplied asset must belong to this post's own client in this org.
        $valid = Asset::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('client_id', $post->client_id)
            ->whereIn('id', $requested)
            ->pluck('id')
            ->all();

        $unknown = array_values(array_diff($requested, $valid));

        if ($unknown !== []) {
            return Response::error('These asset ids are not media for this post\'s client: '.implode(', ', $unknown).'.');
        }

        $current = $post->assets()->pluck('assets.id')->map(fn ($id) => (int) $id)->all();

        $resultIds = match ($mode) {
            'add' => array_values(array_unique([...$current, ...$requested])),
            'remove' => array_values(array_diff($current, $requested)),
            default => $requested, // replace
        };

        $post = ($this->updatePost)($post, ['asset_ids' => $resultIds], $user);

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'mode' => $mode,
            'media_count' => $post->assets()->count(),
            'edit_url' => route('posts.edit', $post),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The post whose media to set.'),
            'asset_ids' => $schema->array()->required()->description('Asset ids to apply (in carousel order for replace/add). Each must belong to the post\'s client.'),
            'mode' => $schema->string()
                ->enum(['replace', 'add', 'remove'])
                ->description('replace (default): these assets become the post\'s media. add: append them. remove: detach them.'),
        ];
    }
}
