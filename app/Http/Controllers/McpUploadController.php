<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\AssetSource;
use App\Http\Requests\StoreAssetRequest;
use App\Models\McpUploadSession;
use App\Models\Post;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Support\Mcp\McpPresenters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * MCP media — the public page a user opens from a `request_media_upload` link to drop images for the
 * MCP assistant. No login and no password: possession of a live `McpUploadSession` token grants
 * exactly one thing — uploading files into its one client's library, and attaching them to the
 * specific posts the session was issued for. Every file still passes the same `SafeUpload` checks as
 * the web uploader, and a per-session file cap bounds abuse (the link isn't single-use so it can take
 * a whole batch). Mirrors `PublicConnectController`.
 */
class McpUploadController extends Controller
{
    public function show(Request $request, string $token): HttpResponse|Response
    {
        $session = McpUploadSession::findActive($token);

        // An unknown or expired link gets a friendly page (410), never a hint of whose it was.
        if ($session === null) {
            return Inertia::render('mcp-upload/show', ['token' => null, 'client' => null, 'posts' => [], 'expires_at' => null])
                ->toResponse($request)
                ->setStatusCode(HttpResponse::HTTP_GONE);
        }

        return Inertia::render('mcp-upload/show', [
            'token' => $token,
            'client' => ['name' => $session->client->name],
            'posts' => $this->postCards($session),
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    public function store(Request $request, string $token, CreateAssetAction $createAsset, UpdatePostAction $updatePost): JsonResponse
    {
        $session = McpUploadSession::findActive($token);

        if ($session === null) {
            return response()->json(['message' => __('This link has expired. Ask your assistant for a new one.')], HttpResponse::HTTP_GONE);
        }

        if (count($session->uploaded_asset_ids ?? []) >= (int) config('mcp.upload.max_files', 50)) {
            return response()->json(['message' => __('This link has reached its upload limit. Ask your assistant for a new one.')], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Same upload hardening as StoreAssetRequest for source=upload; post_id (if any) must be one
        // the session was issued for — never another post, client or org.
        $extensions = array_values(array_map(fn (mixed $ext): string => (string) $ext, (array) config('assets.allowed_upload_extensions')));
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', $extensions),
                $this->maxFileSizeRule(),
                new SafeUpload($extensions),
            ],
            'post_id' => ['nullable', 'integer'],
        ]);

        $postId = isset($validated['post_id']) ? (int) $validated['post_id'] : null;

        if ($postId !== null && ! $session->allowsPost($postId)) {
            return response()->json(['message' => __('That post is not part of this upload link.')], HttpResponse::HTTP_FORBIDDEN);
        }

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $uploader = $session->creator instanceof User ? $session->creator : $session->client->organization?->owner;

        if ($uploader === null) {
            Log::warning('MCP upload session has no member to attribute the upload to', ['mcp_upload_session_id' => $session->id]);

            return response()->json(['message' => __('We couldn’t complete the upload. Ask your assistant to try again.')], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $asset = DB::transaction(function () use ($session, $postId, $file, $uploader, $createAsset, $updatePost) {
            $asset = $createAsset($session->client, $uploader, [
                'source' => AssetSource::Upload,
                'file' => $file,
            ]);

            // Append to the chosen post (keeping existing order), reusing the editor's media sync.
            if ($postId !== null) {
                $post = Post::query()->withoutOrgScope()->whereKey($postId)->firstOrFail();
                $existing = $post->assets()->pluck('assets.id')->map(fn ($id) => (int) $id)->all();
                $updatePost($post, ['asset_ids' => array_values(array_unique([...$existing, $asset->id]))], $uploader);
            }

            $session->forceFill([
                'uploaded_asset_ids' => array_values(array_unique([...($session->uploaded_asset_ids ?? []), $asset->id])),
            ])->save();

            return $asset;
        });

        Log::info('MCP upload received', [
            'org_id' => $session->org_id,
            'client_id' => $session->client_id,
            'mcp_upload_session_id' => $session->id,
            'asset_id' => $asset->id,
            'post_id' => $postId,
        ]);

        return response()->json(['asset' => [
            ...McpPresenters::asset($asset),
            'thumbnail_url' => $asset->thumbnailUrl(),
        ]], HttpResponse::HTTP_CREATED);
    }

    /**
     * The session's target posts as upload-slot cards: a caption excerpt and platform list for context.
     *
     * @return list<array{id: int, caption_excerpt: string|null, platforms: list<string>}>
     */
    private function postCards(McpUploadSession $session): array
    {
        $postIds = $session->post_ids ?? [];

        if ($postIds === []) {
            return [];
        }

        $posts = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $session->org_id)
            ->whereIn('id', $postIds)
            ->with('targets.socialAccount')
            ->get();

        $cards = [];

        foreach ($posts as $post) {
            $platforms = [];

            foreach ($post->targets as $target) {
                $platform = $target->socialAccount?->platform->value;

                if ($platform !== null && ! in_array($platform, $platforms, true)) {
                    $platforms[] = $platform;
                }
            }

            $cards[] = [
                'id' => $post->id,
                'caption_excerpt' => $post->master_caption !== null ? mb_strimwidth((string) $post->master_caption, 0, 120, '…') : null,
                'platforms' => $platforms,
            ];
        }

        return $cards;
    }

    /**
     * Video files get the higher ceiling; everything else the image/document one — same as
     * {@see StoreAssetRequest::maxFileSizeRule()}.
     */
    private function maxFileSizeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $isVideo = str_starts_with((string) $value->getMimeType(), 'video/');
            $maxKb = (int) ($isVideo
                ? config('assets.max_video_upload_size_kb')
                : config('assets.max_upload_size_kb'));

            if ($value->getSize() > $maxKb * 1024) {
                $fail('The '.$attribute.' may not be greater than '.$maxKb.' kilobytes.');
            }
        };
    }
}
