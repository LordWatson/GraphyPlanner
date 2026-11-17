<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Api\ApiResponse;
use App\Support\Api\StoryChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/posts` — what is planned, what went out, and where.
 *
 * The endpoint most integrations actually want: a warehouse pulling what published, a dashboard
 * showing the week ahead, a CRM noting that a client's post went live. Each post carries its
 * channels, because "which account did this go to, and did it work" is the question being asked
 * and making the caller fetch targets separately would mean N+1 over HTTP.
 *
 * `updated_at` filtering is what makes an incremental pull possible: fetch once, then ask for
 * everything changed since. Without it every sync is a full scan.
 *
 * **Writes (item 18 step 2)** create and edit **drafts only**. They go through the same
 * `CreatePostAction`/`UpdatePostAction` the web editor uses, so per-target local-time rules,
 * carousel ordering, the activity log and org scoping all behave identically — an integration
 * cannot reach a path the UI does not have. Moving a post onward is a separate, scoped endpoint
 * (`PostScheduleController`), because scheduling is outward-facing and irreversible while editing
 * a draft is neither.
 */
class PostController extends Controller
{
    /** What `present()` reads for a post's channels (lazy loading is off). */
    public const CHANNELS = ['targets.socialAccount:id,platform,handle', ...StoryChannels::RELATIONS];

    public function index(Request $request): JsonResponse
    {
        // Validated, not trusted: `?updated_since=garbage` used to reach `Carbon::parse` and come
        // back as a 500. A public endpoint must answer a bad parameter with a 422 that says which
        // one, never with a stack trace.
        $request->validate([
            'client_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', Rule::enum(PostStatus::class)],
            'updated_since' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ApiResponse::MAX_PER_PAGE],
        ]);

        $query = Post::query()
            ->with(self::CHANNELS)
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Incremental sync. ISO 8601 in, so a caller never has to guess our timezone.
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', $request->date('updated_since')))
            // Archived posts are dropped work and stay out unless asked for by name, the same rule
            // the content hub uses — an integration should not have to learn that separately.
            ->when(! $request->filled('status'), fn ($q) => $q->where('status', '!=', PostStatus::Archived->value))
            ->orderBy('id');

        return ApiResponse::paginate($request, $query, fn (Post $post) => self::present($post));
    }

    public function show(Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        $post->load(self::CHANNELS);

        return ApiResponse::item(self::present($post));
    }

    /**
     * Create a draft. Never anything further along: a post that could be created already
     * scheduled would skip approval, which is the one thing this product promises not to do.
     */
    public function store(Request $request, CreatePostAction $createPost, UpdatePostAction $updatePost): JsonResponse
    {
        // Which client comes first and on its own: the rest of the rules are scoped to it (a
        // campaign or asset must be *this* client's), so they cannot be written until it resolves.
        // A missing or malformed `client_id` is therefore a 422 that names the field, not a 404.
        $request->validate(['client_id' => ['required', 'integer']]);

        $client = Client::query()->find($request->integer('client_id'));

        // 404 rather than 403: `OrganizationScope` already pinned the org, so "no such client" and
        // "not your client" are the same answer, and distinguishing them would leak the difference.
        if ($client === null) {
            return ApiResponse::error('not_found', 'No client with that id exists for this token.', 404);
        }

        $this->authorize('create', [Post::class, $client]);

        // The same rules as `StorePostRequest` and `CreateDraftPostTool`: every id must belong to
        // this client, so a valid token cannot borrow another client's campaign or asset.
        // Step S-5.4: a channel may also carry its `format`, Story extras and who posts it by hand,
        // under the editor's own rules.
        $validated = StoryChannels::validate($request->all(), 'channels', [
            'caption' => ['nullable', 'string', 'max:65000'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:150'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', 'distinct', Rule::exists('assets', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'channels' => ['nullable', 'array'],
            'channels.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
            'channels.*.scheduled_local_date' => ['nullable', 'date_format:Y-m-d'],
            'channels.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ]);

        $channels = $validated['channels'] ?? [];

        $post = $createPost($client, [
            'status' => PostStatus::Draft->value,
            'master_caption' => $validated['caption'] ?? null,
            'hashtags' => $validated['hashtags'] ?? null,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'targets' => $channels,
        ]);

        // Media, formats and Story extras go through UpdatePostAction so carousel order, the
        // checklist and the one writer of "posted by hand" stay on one path.
        $changes = array_filter([
            'asset_ids' => empty($validated['asset_ids']) ? null : array_values(array_map('intval', $validated['asset_ids'])),
            'targets' => StoryChannels::carryOptions($channels) ? $channels : null,
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $post = $updatePost($post, $changes, $request->user());
        }

        $post->load(self::CHANNELS);

        return response()->json(['data' => self::present($post)], 201);
    }

    /**
     * Edit a draft. Only the fields sent are touched, so a caller that knows about three fields
     * cannot blank the two it has never heard of.
     */
    public function update(Request $request, Post $post, UpdatePostAction $updatePost): JsonResponse
    {
        $this->authorize('update', $post);

        // `PostPolicy::update` has already refused a scheduled or published post with a 403. This
        // narrows the API further than the UI on purpose: once a post is in front of a client for
        // approval, an integration quietly rewriting it is a surprise nobody consented to.
        if (! in_array($post->status, [PostStatus::Idea, PostStatus::Draft], true)) {
            return ApiResponse::error(
                'not_editable',
                sprintf('A post in "%s" cannot be edited over the API. Only ideas and drafts can — move it back to draft in Graphy first.', $post->status->value),
                409,
            );
        }

        $validated = StoryChannels::validate($request->all(), 'channels', [
            'caption' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'hashtags' => ['sometimes', 'nullable', 'array'],
            'hashtags.*' => ['string', 'max:150'],
            'campaign_id' => ['sometimes', 'nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $post->client_id)->whereNull('deleted_at')],
            'asset_ids' => ['sometimes', 'array'],
            'asset_ids.*' => ['integer', 'distinct', Rule::exists('assets', 'id')->where('client_id', $post->client_id)->whereNull('deleted_at')],
            // Step S-5.4: the full set of channels, as the editor saves it. One not listed is
            // removed; a listed one keeps any field that is not sent, except its date and time.
            'channels' => ['sometimes', 'array'],
            'channels.*.social_account_id' => ['required', 'integer', 'distinct', Rule::exists('social_accounts', 'id')->where('client_id', $post->client_id)->whereNull('deleted_at')],
            'channels.*.scheduled_local_date' => ['nullable', 'date_format:Y-m-d'],
            'channels.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ]);

        $changes = [];

        if (array_key_exists('caption', $validated)) {
            $changes['master_caption'] = $validated['caption'];
        }

        if (array_key_exists('hashtags', $validated)) {
            $changes['hashtags'] = $validated['hashtags'];
        }

        if (array_key_exists('campaign_id', $validated)) {
            $changes['campaign_id'] = $validated['campaign_id'];
        }

        if (array_key_exists('asset_ids', $validated)) {
            $changes['asset_ids'] = array_values(array_map('intval', $validated['asset_ids']));
        }

        if (array_key_exists('channels', $validated)) {
            $changes['targets'] = $validated['channels'];
        }

        if ($changes === []) {
            return ApiResponse::error('nothing_to_update', 'Send at least one of caption, hashtags, campaign_id, asset_ids or channels.', 422);
        }

        $post = $updatePost($post, $changes, $request->user());
        $post->load(self::CHANNELS);

        return ApiResponse::item(self::present($post));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Post $post): array
    {
        return [
            'id' => $post->id,
            'client_id' => $post->client_id,
            'campaign_id' => $post->campaign_id,
            'status' => $post->status->value,
            'caption' => $post->master_caption,
            'channels' => $post->targets->map(fn (PostTarget $target) => [
                'social_account_id' => $target->social_account_id,
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'status' => $target->status->value,
                'format' => $target->format,
                // Local date and time as the account sees them, plus the absolute instant. Both,
                // because "9am for the client" and "this exact moment" are different questions and
                // an integration usually wants the second.
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at' => $target->scheduled_at_utc?->toIso8601String(),
                'published_at' => $target->published_at?->toIso8601String(),
                'url' => $target->post_url,
                'error' => $target->error,
                // Step S-5.4: a story with a link or stickers is posted by a person, not by us.
                ...StoryChannels::present($target->setRelation('post', $post)),
            ])->all(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
