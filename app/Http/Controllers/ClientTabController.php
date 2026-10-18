<?php

namespace App\Http\Controllers;

use App\Actions\Clients\BuildClientPageHeaderAction;
use App\Actions\Posts\ListClientPostsAction;
use App\Contracts\CampaignFeatures;
use App\Enums\CampaignFeature;
use App\Enums\PostStatus;
use App\Http\Requests\ClientBillingTabRequest;
use App\Http\Requests\ClientPostsTabRequest;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\CampaignHealthService;
use App\Services\InvoiceLedgerService;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Clients\ClientTabPresenter;
use App\Support\Options\CurrencyOptions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step I-2.4 — one route per client page tab, so deep links and the back button work and each
 * tab loads only its own (paginated) data. `ClientController::show` is the Social accounts tab;
 * every tab shares the header props from `BuildClientPageHeaderAction`.
 */
class ClientTabController extends Controller
{
    public const PER_PAGE = 25;

    public const INVOICES_PER_PAGE = 8;

    public function __construct(
        private readonly BuildClientPageHeaderAction $header,
        private readonly CampaignHealthService $health,
        private readonly CampaignFeatures $features,
    ) {}

    /**
     * Posts are filtered (caption search, status, campaign, channel) and paged in SQL.
     */
    public function posts(ClientPostsTabRequest $request, Client $client, ListClientPostsAction $listPosts): Response
    {
        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('clients/tabs/posts', [
            ...($this->header)($client, $user, 'posts'),
            'posts' => $listPosts($client, $filters)
                ->through(fn (Post $post) => ClientTabPresenter::post($post, $user)),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => array_map(fn (PostStatus $status) => ['value' => $status->value, 'label' => $status->label()], PostStatus::cases()),
                'campaigns' => $client->campaigns()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name]),
            ],
            'targetAccounts' => $client->socialAccounts()
                ->latest()
                ->get()
                ->map(fn (SocialAccount $account) => ClientTabPresenter::targetAccount($account)),
        ]);
    }

    /**
     * The Assets tab renders only the header (its stat card carries the asset count); the library
     * itself is browsed through the paged `clients.assets.index` dialog, so no asset rows load here.
     */
    public function media(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [Asset::class, $client]);

        return Inertia::render('clients/tabs/media', [
            ...($this->header)($client, $request->user(), 'media'),
        ]);
    }

    public function campaigns(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [Campaign::class, $client]);
        $user = $request->user();

        $today = CampaignClock::today($user->organization);
        $page = $client->campaigns()
            ->with(['owner:id,name', 'client:id,name'])
            ->orderByRaw("status = 'archived'")
            ->orderByRaw('start_date is null')
            ->orderByDesc('start_date')
            ->latest('id')
            ->paginate(self::PER_PAGE);
        $health = $this->health->computeMany(new EloquentCollection($page->items()), $today);

        return Inertia::render('clients/tabs/campaigns', [
            ...($this->header)($client, $user, 'campaigns'),
            // Campaigns enhancement: the same presenter as the hub, plus derived health per row.
            'campaigns' => $page->through(fn (Campaign $campaign) => CampaignPresenter::forStaff($campaign, $user, $today, [
                'health' => isset($health[$campaign->id]) ? $health[$campaign->id]->toArray() : null,
                'can' => ['update' => $user->can('update', $campaign), 'delete' => $user->can('delete', $campaign)],
            ], withBilling: false)),
            'formOptions' => CampaignController::formOptions($client->org_id),
            // C-9.3: a client's first campaign is offered a template start, once (empty tab only).
            'starterTemplates' => $page->total() === 0 && $user->can('create', [Campaign::class, $client]) && $this->features->enabled($user->organization, CampaignFeature::Templates)
                ? CampaignTemplate::query()
                    ->where('org_id', $client->org_id)
                    ->orderByDesc('is_starter')
                    ->orderBy('name')
                    ->limit(3)
                    ->get()
                    ->map(fn (CampaignTemplate $template) => CampaignPresenter::templateSummary($template))
                    ->all()
                : [],
            'platformOptions' => CampaignPresenter::platformOptions(),
        ]);
    }

    /**
     * Invoices go through the org ledger's query (scoped to this client), so the tab's status
     * filters — including the derived `overdue` / `outstanding` — mean the same as on the ledger.
     */
    public function billing(ClientBillingTabRequest $request, Client $client, InvoiceLedgerService $ledger): Response
    {
        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('clients/tabs/billing', [
            ...($this->header)($client, $user, 'billing'),
            'invoices' => $ledger->query($client->org_id, [...$filters, 'client_id' => $client->id])
                ->with('items')
                ->paginate(self::INVOICES_PER_PAGE)
                ->withQueryString()
                ->through(fn (Invoice $invoice) => ClientTabPresenter::invoice($invoice, $user)),
            'filters' => $filters,
            // Billing currencies offered by the "New invoice" dialog.
            'currencies' => CurrencyOptions::options(),
        ]);
    }

    public function portalAccess(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [ClientInvitation::class, $client]);
        $user = $request->user();

        return Inertia::render('clients/tabs/portal-access', [
            ...($this->header)($client, $user, 'portal-access'),
            'invitations' => $client->invitations()
                ->with('client:id,org_id')
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->through(fn (ClientInvitation $invitation) => ClientTabPresenter::invitation($invitation, $user)),
        ]);
    }

    /**
     * Trends & ideas are deferred: the tab shell renders straight away and the (unbounded,
     * client-filtered) lists stream in after.
     */
    public function ai(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);
        $user = $request->user();
        abort_unless(BuildClientPageHeaderAction::canUseAi($user), 403);

        return Inertia::render('clients/tabs/ai', [
            ...($this->header)($client, $user, 'ai'),
            'pillars' => $client->brandBrain->content_pillars ?? [],
            'campaignOptions' => $client->campaigns()->orderBy('name')->get(['id', 'name']),
            'trends' => Inertia::defer(fn () => $client->aiTrends()->latest()->latest('id')->get()->map(fn ($trend) => [
                'id' => $trend->id,
                'title' => $trend->title,
                'summary' => $trend->summary,
                'platforms' => $trend->platforms ?? [],
                'confidence' => $trend->confidence,
            ])->values()),
            'ideas' => Inertia::defer(fn () => $client->aiPostIdeas()->latest()->latest('id')->get()->map(fn ($idea) => [
                'id' => $idea->id,
                'title' => $idea->title,
                'description' => $idea->description,
                'platforms' => $idea->platforms ?? [],
                'suggestedCaption' => $idea->suggested_caption,
                'post_id' => $idea->post_id,
            ])->values()),
        ]);
    }

    /**
     * The briefs list itself is fetched by the page from `clients.daily-briefs.index` (JSON).
     */
    public function briefs(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);
        $user = $request->user();
        abort_unless(BuildClientPageHeaderAction::canUseAi($user), 403);

        return Inertia::render('clients/tabs/briefs', [
            ...($this->header)($client, $user, 'briefs'),
        ]);
    }
}
