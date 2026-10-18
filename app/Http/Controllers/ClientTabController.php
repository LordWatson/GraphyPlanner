<?php

namespace App\Http\Controllers;

use App\Actions\Clients\BuildClientPageHeaderAction;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Clients\ClientTabPresenter;
use App\Support\Options\CurrencyOptions;
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

    public const ASSETS_PER_PAGE = 24;

    public function __construct(private readonly BuildClientPageHeaderAction $header) {}

    public function posts(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [Post::class, $client]);
        $user = $request->user();

        return Inertia::render('clients/tabs/posts', [
            ...($this->header)($client, $user, 'posts'),
            'posts' => $client->posts()
                ->with(['targets.socialAccount', 'campaign'])
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->through(fn (Post $post) => ClientTabPresenter::post($post, $user)),
            'targetAccounts' => $client->socialAccounts()
                ->latest()
                ->get()
                ->map(fn (SocialAccount $account) => ClientTabPresenter::targetAccount($account)),
        ]);
    }

    public function media(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [Asset::class, $client]);
        $user = $request->user();

        return Inertia::render('clients/tabs/media', [
            ...($this->header)($client, $user, 'media'),
            'assets' => $client->assets()
                ->latest()
                ->latest('id')
                ->paginate(self::ASSETS_PER_PAGE)
                ->through(fn (Asset $asset) => ClientTabPresenter::asset($asset, $user)),
        ]);
    }

    public function campaigns(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [Campaign::class, $client]);
        $user = $request->user();

        return Inertia::render('clients/tabs/campaigns', [
            ...($this->header)($client, $user, 'campaigns'),
            'campaigns' => $client->campaigns()
                ->latest()
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->through(fn (Campaign $campaign) => ClientTabPresenter::campaign($campaign, $user)),
        ]);
    }

    public function billing(Request $request, Client $client): Response
    {
        $this->authorize('viewBilling', $client);
        $user = $request->user();

        return Inertia::render('clients/tabs/billing', [
            ...($this->header)($client, $user, 'billing'),
            'invoices' => $client->invoices()
                ->with(['items', 'client'])
                ->latest('issue_date')
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->through(fn ($invoice) => ClientTabPresenter::invoice($invoice, $user)),
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
