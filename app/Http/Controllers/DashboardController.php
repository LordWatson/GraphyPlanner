<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetClientHealthSummaryAction;
use App\Actions\Dashboard\GetDashboardNeedsAttentionListAction;
use App\Actions\Dashboard\GetUnapprovedDraftsAction;
use App\Actions\Invoices\GetMonthlyInvoiceTotalsAction;
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
            ->where('org_id', $user->org_id)
            ->whereIn('status', $unapprovedDraftsStatuses);

        if ($user->role === Role::ClientReviewer) {
            $unapprovedDraftsQuery->where('client_id', $user->client_id);
        }

        $unapprovedDraftsCount = $canViewCalendar ? $unapprovedDraftsQuery->count() : 0;

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
                'needsAttention' => $canViewHome
                    ? count($getDashboardNeedsAttentionList($user, 100))
                    : 0,
                'unapprovedDrafts' => $unapprovedDraftsCount,
                'activeClients' => $activeClientsCount,
            ],
            'invoiceTotals' => $canViewInvoiceTotals
                ? $getMonthlyInvoiceTotals($user->org_id)
                : [],
            'upcomingPosts' => $canViewCalendar
                ? $getUpcomingPostOccurrences($user)
                : [],
            'needsAttentionItems' => $canViewHome
                ? $getDashboardNeedsAttentionList($user)
                : [],
            'clientHealth' => $canViewClients
                ? $getClientHealthSummary($user->org_id)
                : [],
            'unapprovedDraftsList' => $canViewCalendar
                ? $getUnapprovedDrafts($user)
                : [],
        ]);
    }
}
