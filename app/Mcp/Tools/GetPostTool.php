<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\McpScope;
use App\Models\Asset;
use App\Models\Post;
use App\Support\Mcp\McpImagePreview;
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
 * Phase MCP-2.2 — a full post: master content, every target rendered in BOTH the account's own local
 * time and the org timezone (so a post with a London and a Dubai target reports both independent
 * times — the invariant MCP-2.2 proves), the attached assets as safe metadata, and the live
 * checklist state from the exact `EvaluatePostChecklistAction` the editor uses, so the reported
 * checklist never drifts. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_post')]
#[Title('Get post')]
#[Description('Returns a full post: caption, hashtags, each target\'s scheduled time in both the account\'s local timezone and the org timezone, attached asset metadata, the live pre-publish checklist, AND (unless include_media is false) the attached images themselves so you can SEE the media and caption from it. Read-only.')]
#[IsReadOnly]
class GetPostTool extends McpTool
{
    public function __construct(private readonly EvaluatePostChecklistAction $evaluateChecklist) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Read];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        $this->actAsOrganizationMember();

        $validated = $request->validate([
            'post_id' => ['required', 'integer'],
            'include_media' => ['nullable', 'boolean'],
        ]);

        $post = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with([
                'client:id,name',
                'campaign:id,name',
                'creator:id,name',
                'assignee:id,name',
                'targets.socialAccount:id,platform,handle,timezone',
                'assets',
            ])
            ->where('id', $validated['post_id'])
            ->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this organization.');
        }

        $checklist = ($this->evaluateChecklist)($post);
        $orgTimezone = $org->default_timezone ?: 'UTC';
        $detail = McpPresenters::postDetail($post, $checklist, $orgTimezone);

        // By default, also show the post's attached images so the assistant can SEE the media and
        // caption from it (keyed by post_id, which clients pass reliably). Keep the structured detail
        // either way; append image previews only when there is viewable media and the caller didn't
        // opt out. Previews are bounded (~768px JPEG) and never a signed URL / storage path.
        $wantsMedia = ($validated['include_media'] ?? true) !== false;
        $images = $wantsMedia ? $this->imageResponses($post) : [];

        if ($images === []) {
            return Response::structured($detail);
        }

        return Response::make([
            Response::text((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ...$images,
        ])->withStructuredContent($detail);
    }

    /**
     * Vision previews of the post's attached media, each preceded by a line naming its asset_id so the
     * model can tie an image to the id it edits. Capped (a carousel is small) and best-effort.
     *
     * @return list<Response>
     */
    private function imageResponses(Post $post): array
    {
        $responses = [];

        foreach ($post->assets->take(10) as $asset) {
            /** @var Asset $asset */
            $preview = McpImagePreview::for($asset);

            if ($preview === null) {
                continue;
            }

            $responses[] = Response::text('Asset '.$asset->id.($asset->original_filename !== null ? ' ('.$asset->original_filename.')' : '').' on this post:');
            $responses[] = Response::image($preview['data'], $preview['mime']);
        }

        return $responses;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The id of the post to fetch.'),
            'include_media' => $schema->boolean()->description('Also return the attached images so you can see them (default true). Set false for text only.'),
        ];
    }
}
