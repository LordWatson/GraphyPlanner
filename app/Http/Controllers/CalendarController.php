<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\GetCalendarCampaignLayerAction;
use App\Actions\Posts\BuildCalendarOccurrencesAction;
use App\Actions\Posts\GetCalendarPostsAction;
use App\Enums\CampaignStatus;
use App\Enums\GridPlanStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Requests\CalendarRequest;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Clients\ClientTabPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * Show the org-wide calendar (Step 0.11): every post's targets, each with both an
     * "account local" and an "org default timezone" rendering, filterable by client/campaign/
     * status/platform. View switching and the local-vs-org-timezone toggle are handled
     * client-side — the server never collapses a target's independently-scheduled local time into
     * a shared instant (spec §4.6).
     *
     * Step I-2.3: only the visible window (`from`/`to`, default: this month's grid) is loaded;
     * month navigation is a partial reload of `occurrences`/`window`/`reschedulablePostIds`, and
     * undated posts come from the optional `unscheduled` prop when the drawer opens.
     */
    public function index(CalendarRequest $request, GetCalendarPostsAction $getPosts, BuildCalendarOccurrencesAction $buildOccurrences, GetCalendarCampaignLayerAction $campaignLayer): Response
    {
        $this->authorize('viewCalendar', Post::class);

        $user = $request->user();
        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';
        $filters = $request->filters();
        $window = $request->window($orgTimezone);
        $readOnly = $request->is('preview', 'preview/*');

        // Lazy and memoized: partial reloads that skip both consumers never run the query.
        $loaded = null;
        $posts = function () use (&$loaded, $getPosts, $user, $filters, $window) {
            return $loaded ??= $getPosts($user, $filters, $window);
        };

        return Inertia::render('calendar/index', [
            'occurrences' => fn () => $buildOccurrences($posts(), $orgTimezone),
            'window' => $window->toArray(),
            'orgTimezone' => $orgTimezone,
            // Posts the viewer may drag to another day. Preview mode is read-only, so none there.
            'reschedulablePostIds' => fn () => $readOnly ? [] : $getPosts->reschedulableIds(
                $user,
                array_values($posts()->pluck('id')->map(fn ($id) => (int) $id)->all()),
            ),
            'unscheduledCount' => fn () => $getPosts->unscheduledCount($user, $filters),
            // Step C-3.5: campaign bands + ghost deliverables for the visible window.
            'campaignLayer' => fn () => $campaignLayer($user, $filters, $window),
            'unscheduled' => Inertia::optional(fn () => $getPosts->unscheduled($user, $filters, $request->integer('unscheduled_page', 1))
                ->through(fn (Post $post) => [
                    'id' => $post->id,
                    'client_name' => $post->client?->name,
                    'status' => $post->status->value,
                    'status_label' => $post->status->label(),
                    'master_caption' => $post->master_caption,
                    'updated_at' => $post->updated_at?->toIso8601String(),
                ])
                ->toArray()),
            'filters' => $filters,
            'filterOptions' => [
                'clients' => Client::query()
                    ->where('org_id', $user->org_id)
                    ->when($user->role === Role::ClientReviewer, fn ($q) => $q->where('id', $user->client_id))
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'campaigns' => Campaign::query()
                    ->where('org_id', $user->org_id)
                    ->when($user->role === Role::ClientReviewer, fn ($q) => $q->where('client_id', $user->client_id))
                    ->orderBy('name')
                    ->get(['id', 'name']),
                // Step 18.11: grids with posts, for "Show this grid".
                'grids' => $user->role === Role::ClientReviewer ? [] : GridPlan::query()
                    ->where('org_id', $user->org_id)
                    ->where('status', '!=', GridPlanStatus::Archived)
                    ->whereHas('tiles', fn ($tiles) => $tiles->whereNotNull('post_id'))
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'statuses' => array_map(
                    fn (PostStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                    PostStatus::cases(),
                ),
                'platforms' => array_map(
                    fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()],
                    Platform::cases(),
                ),
            ],
        ]);
    }

    /**
     * Step M-5.7 / I-5.1 — the target accounts and open campaigns for one client, fetched lazily
     * when "New post" is opened on an empty calendar slot. Scoped and authorized exactly like
     * creating a post for that client, so a client reviewer or another org never sees them.
     */
    public function newPostOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'integer'],
        ]);

        $client = Client::query()
            ->where('org_id', $request->user()->org_id)
            ->whereKey($validated['client'])
            ->firstOrFail();

        $this->authorize('create', [Post::class, $client]);

        return response()->json([
            'accounts' => $client->socialAccounts()
                ->latest()
                ->get()
                ->map(fn (SocialAccount $account) => ClientTabPresenter::targetAccount($account))
                ->all(),
            'campaigns' => $client->campaigns()
                ->whereIn('status', CampaignStatus::open())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name])
                ->all(),
        ]);
    }
}
