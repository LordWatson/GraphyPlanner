<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\GetPortalCalendarPostsAction;
use App\Actions\Posts\BuildCalendarOccurrencesAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client portal's content calendar — the portal counterpart to the internal
 * `CalendarController`. Scoped to the logged-in contact's own client and to
 * `PostStatus::clientVisible()` via `GetPortalCalendarPostsAction`; occurrences are built by the
 * same `BuildCalendarOccurrencesAction` as the internal calendar so both render identically.
 */
class PortalCalendarController extends Controller
{
    public function index(
        Request $request,
        GetPortalCalendarPostsAction $getPosts,
        BuildCalendarOccurrencesAction $buildOccurrences,
    ): Response {
        $user = $request->user();
        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';

        $status = PostStatus::tryFrom($request->string('status')->toString());
        $status = $status?->isClientVisible() ? $status : null;
        $platform = Platform::tryFrom($request->string('platform')->toString());
        $campaignId = $request->integer('campaign_id') ?: null;

        $posts = $getPosts($user, $status, $campaignId, $platform);

        return Inertia::render('portal/calendar', [
            'client' => ['name' => $user->client->name],
            'occurrences' => $buildOccurrences($posts, $orgTimezone),
            'orgTimezone' => $orgTimezone,
            'filters' => [
                'client_id' => null,
                'campaign_id' => $campaignId,
                'status' => $status?->value,
                'platform' => $platform?->value,
            ],
            'filterOptions' => [
                'clients' => [],
                'campaigns' => Campaign::query()
                    ->where('client_id', $user->client_id)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'statuses' => array_map(
                    fn (PostStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                    PostStatus::clientVisible(),
                ),
                'platforms' => array_map(
                    fn (Platform $p) => ['value' => $p->value, 'label' => $p->label()],
                    Platform::cases(),
                ),
            ],
        ]);
    }
}
