<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Api\ApiResponse;
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
 */
class PostController extends Controller
{
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
            ->with(['targets' => fn ($q) => $q->with('socialAccount:id,platform,handle')])
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

        $post->load(['targets' => fn ($q) => $q->with('socialAccount:id,platform,handle')]);

        return ApiResponse::item(self::present($post));
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Post $post): array
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
                // Local date and time as the account sees them, plus the absolute instant. Both,
                // because "9am for the client" and "this exact moment" are different questions and
                // an integration usually wants the second.
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at' => $target->scheduled_at_utc?->toIso8601String(),
                'published_at' => $target->published_at?->toIso8601String(),
                'url' => $target->post_url,
                'error' => $target->error,
            ])->all(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
