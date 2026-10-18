<?php

namespace App\Http\Controllers;

use App\Actions\Posts\BuildCalendarOccurrencesAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
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
    public function index(Request $request, BuildCalendarOccurrencesAction $buildOccurrences): Response
    {
        $this->authorize('viewCalendar', Post::class);

        $user = $request->user();
        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';

        $query = Post::query()
            ->forActiveClients()
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
            'occurrences' => $buildOccurrences($posts, $orgTimezone),
            'orgTimezone' => $orgTimezone,
            // Posts the viewer may drag to another day. Preview mode is read-only, so none there.
            'reschedulablePostIds' => $request->is('preview', 'preview/*') ? [] : $posts
                ->filter(fn (Post $post) => $user->can('reschedule', $post))
                ->pluck('id')
                ->values(),
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
}
