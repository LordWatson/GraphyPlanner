<?php

namespace App\Http\Controllers\Business;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Posts\CreateQuickPostAction;
use App\Actions\Posts\QuickSchedulePostAction;
use App\Enums\AssetSource;
use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuickPostRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Rules\SafeUpload;
use App\Services\Business\NextSlotService;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Workspace\PostingGoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-7.2 / B-7.3 — the phone-first quick post. A single mobile screen: a captured photo/video →
 * AI captions (B-6.2) → the accounts to post to (defaults remembered client-side) → "Next free
 * slot" / "Now" / a picked time → schedule. Thin, like every business controller: it resolves the
 * implicit brand from {@see CurrentWorkspace} and composes the shared {@see CreateQuickPostAction}
 * and {@see QuickSchedulePostAction}. `share` is the Web Share Target landing (B-7.3): it ingests
 * the shared media and bounces into the same flow with it attached.
 */
class QuickPostController extends Controller
{
    public function create(Request $request, CurrentWorkspace $workspace, NextSlotService $nextSlot): Response
    {
        $brand = $workspace->brand();

        $this->authorize('create', [Post::class, $brand]);

        $accounts = $brand->socialAccounts()
            ->latest()
            ->get()
            // Connected accounts first — they're the ones the brand will usually post to.
            ->sortByDesc(fn (SocialAccount $account) => $account->connection_status === ConnectionStatus::Connected ? 1 : 0)
            ->values();

        $timezone = $workspace->organization()->default_timezone ?? 'UTC';
        $suggested = $nextSlot->next(
            PostingGoal::fromArray($brand->posting_goal),
            $timezone,
            Carbon::now(),
            $this->scheduledLocalDates($brand),
            (int) config('ai.plan.default_hour', 9),
        );

        return Inertia::render('business/posts/quick', [
            'accounts' => $accounts->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'connected' => $account->connection_status === ConnectionStatus::Connected,
            ])->values(),
            // A media id handed over by the Share Target (B-7.3) or a deep link — preloaded so the
            // capture step is already done.
            'preloadAsset' => $this->preloadAsset($request, $brand->id),
            'prefillCaption' => $this->prefillCaption($request),
            'suggestedSlot' => [
                'date' => $suggested['date'],
                'time' => $suggested['time'],
                'label' => Carbon::parse($suggested['date'].' '.$suggested['time'])->isoFormat('ddd D MMM, HH:mm'),
            ],
            'timezone' => $timezone,
            'aiEnabled' => (bool) config('ai.enabled', false),
        ]);
    }

    public function store(
        StoreQuickPostRequest $request,
        CurrentWorkspace $workspace,
        CreateQuickPostAction $createQuickPost,
        QuickSchedulePostAction $quickSchedule,
        NextSlotService $nextSlot,
    ): RedirectResponse {
        $brand = $workspace->brand();
        $data = $request->validated();
        $when = $data['when'];

        [$date, $time, $scheduleWhen] = $this->resolveTiming($request, $workspace, $nextSlot, $brand, $when, $data);

        $post = $createQuickPost(
            $brand,
            (int) $data['asset_id'],
            (string) $data['caption'],
            array_values(array_map('intval', $data['account_ids'])),
            $date,
            $time,
        );

        try {
            $scheduled = $quickSchedule($post, $request->user(), $scheduleWhen);
        } catch (ValidationException $e) {
            // Not ready to go out (e.g. the photo doesn't fit a channel's format): keep the draft and
            // open the full editor so they can finish, with the reason shown.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Almost there — just a couple of things to finish.')]);

            return to_route('posts.edit', $post)->withErrors($e->errors());
        }

        $message = $scheduled->status === PostStatus::InternalReview
            ? __('Sent for review.')
            : __('Done — your post is scheduled.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('business.today');
    }

    /**
     * Step B-7.3 — the Web Share Target POST. The phone shares a photo/video (and maybe a caption)
     * to Alima; we ingest the media as an asset and 303-redirect into the quick post flow with it
     * attached. Auth + `workspace:business` already gate the route; the media is validated here.
     */
    public function share(Request $request, CurrentWorkspace $workspace, CreateAssetAction $createAsset): RedirectResponse
    {
        $brand = $workspace->brand();

        $this->authorize('create', [Asset::class, $brand]);

        $validated = $request->validate([
            'media' => [
                'required',
                'file',
                'mimes:'.implode(',', config('assets.allowed_upload_extensions')),
                new SafeUpload(config('assets.allowed_upload_extensions')),
            ],
            'title' => ['nullable', 'string', 'max:300'],
            'text' => ['nullable', 'string', 'max:5000'],
        ]);

        $asset = $createAsset($brand, $request->user(), [
            'source' => AssetSource::Upload->value,
            'file' => $validated['media'],
        ]);

        $caption = trim(implode("\n\n", array_filter([
            $validated['title'] ?? null,
            $validated['text'] ?? null,
        ])));

        return to_route('business.posts.quick', array_filter([
            'asset' => $asset->id,
            'text' => $caption !== '' ? $caption : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string, 2: 'now'|'schedule'} [localDate, localTime, QuickSchedule "when"]
     */
    private function resolveTiming(Request $request, CurrentWorkspace $workspace, NextSlotService $nextSlot, Client $brand, string $when, array $data): array
    {
        if ($when === QuickSchedulePostAction::WHEN_NOW) {
            // Times are set by QuickSchedulePostAction (retime-to-now); no local date/time needed.
            return [null, null, QuickSchedulePostAction::WHEN_NOW];
        }

        if ($when === 'next') {
            $slot = $nextSlot->next(
                PostingGoal::fromArray($brand->posting_goal),
                $workspace->organization()->default_timezone ?? 'UTC',
                Carbon::now(),
                $this->scheduledLocalDates($brand),
                (int) config('ai.plan.default_hour', 9),
            );

            return [$slot['date'], $slot['time'], QuickSchedulePostAction::WHEN_SCHEDULED];
        }

        // A specific time the user picked.
        $date = $data['scheduled_local_date'] ?? null;
        $time = $data['scheduled_local_time'] ?? null;

        return [
            is_string($date) ? $date : null,
            is_string($time) ? $time : null,
            QuickSchedulePostAction::WHEN_SCHEDULED,
        ];
    }

    /**
     * @return list<string>
     */
    private function scheduledLocalDates(Client $brand): array
    {
        return array_values(PostTarget::query()
            ->whereNotNull('scheduled_local_date')
            ->whereIn('post_id', $brand->posts()->where('status', PostStatus::Scheduled)->select('id'))
            ->pluck('scheduled_local_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array{id: int, url: string, thumbnail_url: string|null, type: string|null}|null
     */
    private function preloadAsset(Request $request, int $brandId): ?array
    {
        $id = $request->integer('asset');

        if ($id <= 0) {
            return null;
        }

        $asset = Asset::query()->where('client_id', $brandId)->find($id);

        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'url' => $asset->url,
            'thumbnail_url' => $asset->thumbnailUrl(),
            'type' => $asset->type?->value,
        ];
    }

    private function prefillCaption(Request $request): ?string
    {
        $text = trim((string) $request->query('text', ''));

        return $text !== '' ? mb_substr($text, 0, 5000) : null;
    }
}
