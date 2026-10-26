<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\McpScope;
use App\Enums\PostStatus;
use App\Models\Client;
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
 * Phase MCP-3.1 — create a DRAFT post, reusing the exact `CreatePostAction` the web editor uses, so
 * the per-target independent local-time rules, defaults and org-scoping all apply unchanged. It only
 * ever creates a draft (never publishes), so it carries no destructive annotation — but it still
 * requires the elevated `WriteDrafts` scope (a read-only token is rejected before this runs) and the
 * acting member must pass the same `create` policy the UI enforces. Returns the new post's id and a
 * link so a human can review it in Graphy.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('create_draft_post')]
#[Title('Create draft post')]
#[Description('Creates a DRAFT post for a client (never published). Accepts caption, hashtags, optional campaign, and per-account targets with their own local date/time. Requires the write:drafts scope. Returns the new post id and an edit link.')]
class CreateDraftPostTool extends McpTool
{
    public function __construct(
        private readonly CreatePostAction $createPost,
        private readonly UpdatePostAction $updatePost,
    ) {}

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

        $clientId = (int) $request->get('client_id');

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $clientId)
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        if ($user->cannot('create', [Post::class, $client])) {
            return Response::error('You are not allowed to create posts for this client.');
        }

        // Validated like StorePostRequest: targets must reference the client's own live accounts.
        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'master_caption' => ['nullable', 'string', 'max:65000'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:150'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', 'distinct', Rule::exists('assets', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date_format:Y-m-d'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ]);

        $post = ($this->createPost)($client, [
            'status' => PostStatus::Draft->value,
            'master_caption' => $validated['master_caption'] ?? null,
            'hashtags' => $validated['hashtags'] ?? null,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'targets' => $validated['targets'] ?? [],
        ]);

        // Attach media through UpdatePostAction so the carousel order + §6 checklist stay in one path.
        if (! empty($validated['asset_ids'])) {
            $post = ($this->updatePost)($post, [
                'asset_ids' => array_values(array_map('intval', $validated['asset_ids'])),
            ], $user);
        }

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'client_id' => $client->id,
            'media_count' => $post->assets()->count(),
            'edit_url' => route('posts.edit', $post),
            'message' => 'Draft created. Review and refine it in Graphy before scheduling.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client the post is for.'),
            'master_caption' => $schema->string()->description('The master caption; per-account overrides can be edited later.'),
            'hashtags' => $schema->array()->description('A list of hashtag strings (without the leading #).'),
            'campaign_id' => $schema->integer()->description('Attach the draft to this campaign (must belong to the client).'),
            'asset_ids' => $schema->array()->description('Media asset ids to attach, in carousel order (use upload_media to add a new image first). Each must belong to the client.'),
            'targets' => $schema->array()->description('Per-account targets: objects with social_account_id and optional scheduled_local_date (YYYY-MM-DD) and scheduled_local_time (HH:MM).'),
        ];
    }
}
