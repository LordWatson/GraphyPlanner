<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\UpdatePostAction;
use App\Enums\McpScope;
use App\Models\Post;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Phase MCP-3.2 — edit a draft post (caption, hashtags, link, first comment, campaign, targets),
 * reusing the exact `UpdatePostAction` + `UpdatePostRequest` validation the web editor uses. It is
 * explicit about what it changed: only the fields the caller supplied are touched, and the response
 * returns the before→after diff for each. Requires the `WriteDrafts` scope and the `update` policy.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('update_post')]
#[Title('Update post')]
#[Description('Edits a post\'s caption, hashtags, link, first comment, campaign or targets, reusing the web editor\'s validation. Only the fields you supply change; returns the before→after diff. Requires the write:drafts scope.')]
class UpdatePostTool extends McpTool
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

        $clientId = $post->client_id;

        $validated = $request->validate([
            'post_id' => ['required', 'integer'],
            'master_caption' => ['nullable', 'string', 'max:65000'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:150'],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'first_comment' => ['nullable', 'string', 'max:3000'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', 'distinct', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date_format:Y-m-d'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ]);

        // Snapshot only the scalar fields the caller actually supplied — the diff reports just these.
        $trackable = ['master_caption', 'hashtags', 'link_url', 'first_comment', 'campaign_id'];
        $before = [];

        foreach ($trackable as $field) {
            if (array_key_exists($field, $validated)) {
                $before[$field] = $post->getAttribute($field);
            }
        }

        $data = array_diff_key($validated, array_flip(['post_id']));
        $post = ($this->updatePost)($post, $data, $user);

        $diff = [];

        foreach ($before as $field => $old) {
            $new = $post->getAttribute($field);

            if ($old !== $new) {
                $diff[$field] = ['from' => $old, 'to' => $new];
            }
        }

        if (array_key_exists('targets', $validated)) {
            $diff['targets'] = ['count' => $post->targets()->count()];
        }

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'changed' => $diff,
            'edit_url' => route('posts.edit', $post),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The post to edit.'),
            'master_caption' => $schema->string()->description('Replace the master caption.'),
            'hashtags' => $schema->array()->description('Replace the hashtag list (strings without #).'),
            'link_url' => $schema->string()->description('Set the link URL (http/https).'),
            'first_comment' => $schema->string()->description('Set the first comment.'),
            'campaign_id' => $schema->integer()->description('Move the post to this campaign (must belong to the same client).'),
            'targets' => $schema->array()->description('Replace the targets: objects with social_account_id and optional scheduled_local_date/scheduled_local_time. Unlisted channels are removed.'),
        ];
    }
}
