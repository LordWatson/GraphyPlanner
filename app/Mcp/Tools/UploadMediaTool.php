<?php

namespace App\Mcp\Tools;

use App\Actions\Assets\CreateAssetFromBase64Action;
use App\Enums\McpScope;
use App\Models\Asset;
use App\Models\Client;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Phase MCP — ingest an image (or other allowed media) the user put into their AI assistant into a
 * client's media library, so they can say "create a post using this image". MCP tool calls carry no
 * binary channel, so the bytes arrive base64-encoded in `data`. The upload runs through the exact
 * web-upload safety pipeline (`CreateAssetFromBase64Action` → `SafeUpload` → `CreateAssetAction`):
 * nothing the web uploader would reject (wrong type, oversized, renamed script, virus) can be stored.
 *
 * Requires the elevated `WriteDrafts` scope and the same `AssetPolicy::create` the web UI enforces.
 * Returns only SAFE asset metadata (never a signed URL or storage path) plus the new `asset_id`, which
 * the assistant then passes to `create_draft_post` or `set_post_media`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('upload_media')]
#[Title('Upload media')]
#[Description('Uploads an image (or other allowed media) into a client\'s media library from base64-encoded bytes, so it can be attached to a post. Validated exactly like a web upload (type, size, content sniffing, virus scan). Requires the write:drafts scope. Returns the new asset id and safe metadata — never a download URL.')]
class UploadMediaTool extends McpTool
{
    public function __construct(private readonly CreateAssetFromBase64Action $createAssetFromBase64) {}

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

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', (int) $request->get('client_id'))
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        if ($user->cannot('create', [Asset::class, $client])) {
            return Response::error('You are not allowed to upload media for this client.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'data' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
        ]);

        try {
            $asset = ($this->createAssetFromBase64)($client, $user, [
                'data' => $validated['data'],
                'filename' => $validated['filename'] ?? null,
                'campaign_id' => $validated['campaign_id'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->first() ?? 'The media could not be uploaded.');
        }

        return Response::structured([
            'asset_id' => $asset->id,
            'asset' => McpPresenters::asset($asset),
            'message' => 'Media uploaded. Pass asset_id to create_draft_post or set_post_media to use it on a post.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client whose media library this is added to.'),
            'data' => $schema->string()->required()->description('The file as base64-encoded bytes (a data: URL prefix is accepted). Images are the expected use.'),
            'filename' => $schema->string()->description('The original filename, e.g. "hero.jpg" — its extension must match the content. If omitted, it is inferred from the content.'),
            'mime_type' => $schema->string()->description('Optional MIME type hint; the content is sniffed regardless.'),
            'campaign_id' => $schema->integer()->description('Attach the asset to this campaign (must belong to the client).'),
        ];
    }
}
