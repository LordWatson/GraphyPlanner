<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * Show the org-wide calendar (Step 0.11): every post's targets, each with both an
     * "account local" and an "org default timezone" rendering, filterable by client/campaign/
     * status/platform. Month/week/day/list view switching and the local-vs-org-timezone toggle
     * are handled client-side against this single payload — the server never collapses a target's
     * independently-scheduled local time into a shared instant (spec §4.6).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewCalendar', Post::class);

        $user = $request->user();
        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';

        $query = Post::query()
            ->where('org_id', $user->org_id)
            ->with(['client:id,name', 'campaign:id,name', 'targets.socialAccount']);

        if ($user->role === Role::ClientReviewer) {
            $query->where('client_id', $user->client_id);
        } elseif ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->integer('campaign_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('platform')) {
            $platform = $request->string('platform')->toString();
            $query->whereHas('targets.socialAccount', fn ($q) => $q->where('platform', $platform));
        }

        $posts = $query->get();

        return Inertia::render('calendar/index', [
            'occurrences' => $this->transformOccurrences($posts, $orgTimezone),
            'orgTimezone' => $orgTimezone,
            'filters' => [
                'client_id' => $request->integer('client_id') ?: null,
                'campaign_id' => $request->integer('campaign_id') ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'platform' => $request->string('platform')->toString() ?: null,
            ],
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
     * Flatten every post's targets into one calendar "occurrence" per target, with the local
     * date/time pre-computed both in the target's own social account timezone and in the org's
     * default timezone, so the frontend toggle never needs to do its own timezone math.
     *
     * @param  \Illuminate\Support\Collection<int, Post>  $posts
     * @return array<int, array<string, mixed>>
     */
    private function transformOccurrences($posts, string $orgTimezone): array
    {
        $occurrences = [];

        foreach ($posts as $post) {
            foreach ($post->targets as $target) {
                $utc = $target->scheduled_at_utc;
                $account = $target->socialAccount;

                $occurrences[] = [
                    'post_id' => $post->id,
                    'target_id' => $target->id,
                    'client_id' => $post->client_id,
                    'client_name' => $post->client?->name,
                    'campaign_id' => $post->campaign_id,
                    'campaign_name' => $post->campaign?->name,
                    'status' => $post->status->value,
                    'status_label' => $post->status->label(),
                    'master_caption' => $post->master_caption,
                    'platform' => $account?->platform?->value,
                    'handle' => $account?->handle,
                    'account_local' => [
                        'date' => $target->scheduled_local_date?->toDateString(),
                        'time' => $target->scheduled_local_time,
                        'timezone' => $account?->timezone,
                    ],
                    'org_timezone' => [
                        'date' => $utc?->clone()->setTimezone($orgTimezone)->toDateString(),
                        'time' => $utc?->clone()->setTimezone($orgTimezone)->format('H:i'),
                        'timezone' => $orgTimezone,
                    ],
                    'scheduled_at_utc' => $utc?->toIso8601String(),
                ];
            }
        }

        return $occurrences;
    }
}
