<?php

namespace App\Http\Controllers;

use App\Actions\Clients\CreateClientAction;
use App\Actions\Clients\UpdateClientAction;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Display a listing of the organization's clients.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();

        $clients = Client::query()
            ->where('org_id', $user->org_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => $this->transform($client, $user->can('viewBilling', $client)));

        return Inertia::render('clients/index', [
            'clients' => $clients,
            'can' => [
                'create' => $user->can('create', Client::class),
            ],
        ]);
    }

    /**
     * Show the form for creating a new client.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create', [
            'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
            'billingCycles' => array_map(fn (BillingCycle $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], BillingCycle::cases()),
        ]);
    }

    /**
     * Store a newly created client.
     */
    public function store(StoreClientRequest $request, CreateClientAction $action): RedirectResponse
    {
        $client = $action($request->user(), $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Display the specified client.
     */
    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $canViewBilling = $user->can('viewBilling', $client);

        $canViewSocialAccounts = $user->can('viewAny', [SocialAccount::class, $client]);
        $canViewCampaigns = $user->can('viewAny', [Campaign::class, $client]);
        $canViewAssets = $user->can('viewAny', [Asset::class, $client]);
        $canViewPosts = $user->can('viewAny', [Post::class, $client]);

        $campaigns = $canViewCampaigns
            ? $client->campaigns()->latest()->get()
            : collect();

        $socialAccounts = $canViewSocialAccounts || $canViewPosts
            ? $client->socialAccounts()->latest()->get()
            : collect();

        return Inertia::render('clients/show', [
            'client' => $this->transform($client, $canViewBilling),
            'can' => [
                'update' => $user->can('update', $client),
                'delete' => $user->can('delete', $client),
                'createInvoice' => $user->can('create', [Invoice::class, $client]),
                'createSocialAccount' => $user->can('create', [SocialAccount::class, $client]),
                'createCampaign' => $user->can('create', [Campaign::class, $client]),
                'createAsset' => $user->can('create', [Asset::class, $client]),
                'createPost' => $user->can('create', [Post::class, $client]),
            ],
            'invoices' => $canViewBilling
                ? $client->invoices()
                    ->latest('issue_date')
                    ->get()
                    ->map(fn (Invoice $invoice) => $this->transformInvoice($invoice, $user))
                : [],
            'socialAccounts' => $canViewSocialAccounts
                ? $socialAccounts->map(fn (SocialAccount $socialAccount) => $this->transformSocialAccount($socialAccount, $user))
                : [],
            'campaigns' => $canViewCampaigns
                ? $campaigns->map(fn (Campaign $campaign) => $this->transformCampaign($campaign, $user))
                : [],
            'assets' => $canViewAssets
                ? $client->assets()
                    ->latest()
                    ->get()
                    ->map(fn (Asset $asset) => $this->transformAsset($asset, $user))
                : [],
            'posts' => $canViewPosts
                ? $client->posts()
                    ->with('targets.socialAccount', 'campaign')
                    ->latest()
                    ->get()
                    ->map(fn (Post $post) => $this->transformPost($post, $user))
                : [],
            'targetAccounts' => $canViewPosts
                ? $socialAccounts->map(fn (SocialAccount $socialAccount) => [
                    'id' => $socialAccount->id,
                    'platform' => $socialAccount->platform->value,
                    'handle' => $socialAccount->handle,
                    'timezone' => $socialAccount->timezone,
                ])
                : [],
        ]);
    }

    /**
     * Show the form for editing the specified client.
     */
    public function edit(Request $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', [
            'client' => $this->transform($client, $request->user()->can('viewBilling', $client)),
            'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
            'billingCycles' => array_map(fn (BillingCycle $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], BillingCycle::cases()),
        ]);
    }

    /**
     * Update the specified client.
     */
    public function update(UpdateClientRequest $request, Client $client, UpdateClientAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return to_route('clients.index');
    }

    /**
     * Transform a client model into an array, hiding billing fields when not permitted.
     *
     * @return array<string, mixed>
     */
    private function transform(Client $client, bool $canViewBilling): array
    {
        $health = $client->health();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'website' => $client->website,
            'industry' => $client->industry,
            'countries' => $client->countries,
            'status' => $client->status->value,
            'owner_user_id' => $client->owner_user_id,
            'start_date' => $client->start_date?->toDateString(),
            'retainer_amount' => $canViewBilling ? $client->retainer_amount : null,
            'billing_cycle' => $canViewBilling ? $client->billing_cycle?->value : null,
            'tags' => $client->tags,
            'default_language' => $client->default_language,
            'notes_internal' => $client->notes_internal,
            'approval_email' => $client->approval_email,
            'health' => $health['status']->value,
            'health_reason' => $health['reason'],
        ];
    }

    /**
     * Transform an invoice model into an array for the invoice history list.
     *
     * @return array<string, mixed>
     */
    private function transformInvoice(Invoice $invoice, User $user): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'description' => $invoice->description,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'can' => [
                'send' => $invoice->status === InvoiceStatus::Draft && $user->can('send', $invoice),
                'mark_paid' => $invoice->status === InvoiceStatus::Sent && $user->can('markPaid', $invoice),
            ],
        ];
    }

    /**
     * Transform a social account model into an array for the client page.
     *
     * @return array<string, mixed>
     */
    private function transformSocialAccount(SocialAccount $socialAccount, User $user): array
    {
        return [
            'id' => $socialAccount->id,
            'platform' => $socialAccount->platform->value,
            'handle' => $socialAccount->handle,
            'display_name' => $socialAccount->display_name,
            'timezone' => $socialAccount->timezone,
            'language' => $socialAccount->language,
            'country' => $socialAccount->country,
            'default_location' => $socialAccount->default_location,
            'posting_windows' => $socialAccount->posting_windows,
            'persona_override' => $socialAccount->persona_override,
            'connection_status' => $socialAccount->connection_status->value,
            'can' => [
                'update' => $user->can('update', $socialAccount),
                'delete' => $user->can('delete', $socialAccount),
            ],
        ];
    }

    /**
     * Transform a campaign model into an array for the client page.
     *
     * @return array<string, mixed>
     */
    private function transformCampaign(Campaign $campaign, User $user): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'goal' => $campaign->goal,
            'notes' => $campaign->notes,
            'can' => [
                'update' => $user->can('update', $campaign),
                'delete' => $user->can('delete', $campaign),
            ],
        ];
    }

    /**
     * Transform a post model into an array for the client page.
     *
     * @return array<string, mixed>
     */
    private function transformPost(Post $post, User $user): array
    {
        return [
            'id' => $post->id,
            'campaign_id' => $post->campaign_id,
            'campaign_name' => $post->campaign?->name,
            'status' => $post->status->value,
            'approval_mode' => $post->approval_mode->value,
            'master_caption' => $post->master_caption,
            'hashtags' => $post->hashtags,
            'targets' => $post->targets->map(fn ($target) => [
                'id' => $target->id,
                'social_account_id' => $target->social_account_id,
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at_utc' => $target->scheduled_at_utc?->toIso8601String(),
            ])->values(),
            'created_at' => $post->created_at?->toIso8601String(),
            'can' => [
                'update' => $user->can('update', $post),
                'delete' => $user->can('delete', $post),
            ],
        ];
    }

    /**
     * Transform an asset model into an array for the client page.
     *
     * @return array<string, mixed>
     */
    private function transformAsset(Asset $asset, User $user): array
    {
        return [
            'id' => $asset->id,
            'campaign_id' => $asset->campaign_id,
            'source' => $asset->source->value,
            'type' => $asset->type?->value,
            'url' => $asset->url,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'rights' => $asset->rights,
            'variant_group_id' => $asset->variant_group_id,
            'can' => [
                'delete' => $user->can('delete', $asset),
            ],
        ];
    }
}
