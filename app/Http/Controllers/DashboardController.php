<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\GetLiveCampaignsAction;
use App\Actions\Dashboard\GetClientHealthSummaryAction;
use App\Actions\Dashboard\GetDashboardNeedsAttentionListAction;
use App\Actions\Dashboard\GetUnapprovedDraftsAction;
use App\Actions\Invoices\GetMonthlyInvoiceTotalsAction;
use App\Actions\Posts\GetPublishReliabilityStatsAction;
use App\Actions\Posts\GetUpcomingPostCountAction;
use App\Actions\Posts\GetUpcomingPostOccurrencesAction;
use App\Enums\ClientStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with summary counts scoped to the user's organization.
     */
    public function __invoke(
        Request $request,
        GetMonthlyInvoiceTotalsAction $getMonthlyInvoiceTotals,
        GetUpcomingPostOccurrencesAction $getUpcomingPostOccurrences,
        GetUpcomingPostCountAction $getUpcomingPostCount,
        GetDashboardNeedsAttentionListAction $getDashboardNeedsAttentionList,
        GetClientHealthSummaryAction $getClientHealthSummary,
        GetUnapprovedDraftsAction $getUnapprovedDrafts,
        GetPublishReliabilityStatsAction $getPublishReliabilityStats,
        GetLiveCampaignsAction $getLiveCampaigns,
    ): Response {
        $user = $request->user();

        $canViewClients = $user->can('viewAny', Client::class);
        $clientsCount = $canViewClients
            ? Client::query()->where('org_id', $user->org_id)->count()
            : 0;
        $activeClientsCount = $canViewClients
            ? Client::query()->where('org_id', $user->org_id)->where('status', ClientStatus::Active)->count()
            : 0;

        $canViewInvoiceTotals = in_array($user->role, [Role::Owner, Role::Strategist], true);
        $canViewCalendar = $user->can('viewCalendar', Post::class);
        $canViewHome = $user->can('viewHome', Post::class);

        $unapprovedDraftsStatuses = [PostStatus::Draft, PostStatus::InternalReview, PostStatus::ChangesRequested];
        $unapprovedDraftsQuery = Post::query()
            ->forActiveClients()
            ->where('org_id', $user->org_id)
            ->whereIn('status', $unapprovedDraftsStatuses);

        if ($user->role === Role::ClientReviewer) {
            $unapprovedDraftsQuery->where('client_id', $user->client_id);
        }

        $unapprovedDraftsCount = $canViewCalendar ? $unapprovedDraftsQuery->count() : 0;

        $needsAttention = $canViewHome
            ? $getDashboardNeedsAttentionList($user)
            : ['rows' => [], 'total' => 0];

        return Inertia::render('dashboard', [
            'summary' => [
                'clients' => $clientsCount,
                'brands' => 0,
                'scheduledPosts' => $canViewCalendar
                    ? $getUpcomingPostCount($user)
                    : 0,
                'scheduledNext24h' => $canViewCalendar
                    ? $getUpcomingPostCount($user, 1)
                    : 0,
                'needsAttention' => $needsAttention['total'],
                'unapprovedDrafts' => $unapprovedDraftsCount,
                'activeClients' => $activeClientsCount,
            ],
            'invoiceTotals' => $canViewInvoiceTotals
                ? $getMonthlyInvoiceTotals($user->org_id)
                : [],
            'upcomingPosts' => $canViewCalendar
                ? $getUpcomingPostOccurrences($user)
                : [],
            'needsAttentionItems' => $needsAttention['rows'],
            'clientHealth' => $canViewClients
                ? $getClientHealthSummary($user->org_id, viewer: $user)
                : [],
            'unapprovedDraftsList' => $canViewCalendar
                ? $getUnapprovedDrafts($user)
                : [],
            // Campaigns enhancement C-1.4: deferred, so the Overview paints first.
            'liveCampaigns' => $canViewClients && $user->role !== Role::ClientReviewer
                ? Inertia::defer(fn () => $getLiveCampaigns($user))
                : ['items' => [], 'total' => 0],
            'publishReliability' => $canViewCalendar
                ? $getPublishReliabilityStats($user->org_id)
                : ['monthly' => [], 'averageAttemptsToSuccess' => null],
        ]);
    }
}
