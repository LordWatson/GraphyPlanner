<?php

namespace App\Actions\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\DailyBrief;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Clients\ClientPresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step I-2.4 — the props every client tab page shares through `ClientLayout`: the client, the
 * viewer's abilities, which tabs exist for them, and the header's stat cards. The cards are SQL
 * aggregates (one `withCount` query plus a per-currency sum), so the header no longer needs every
 * post, asset and invoice on the page just to count them.
 */
class BuildClientPageHeaderAction
{
    /**
     * @return array{client: array<string, mixed>, can: array<string, bool>, tab: string, stats: array<string, mixed>}
     */
    public function __invoke(Client $client, User $user, string $tab): array
    {
        $can = [
            'update' => $user->can('update', $client),
            'delete' => $user->can('delete', $client),
            'viewBilling' => $user->can('viewBilling', $client),
            'viewInvitations' => $user->can('viewAny', [ClientInvitation::class, $client]),
            'viewSocialAccounts' => $user->can('viewAny', [SocialAccount::class, $client]),
            'viewCampaigns' => $user->can('viewAny', [Campaign::class, $client]),
            'viewAssets' => $user->can('viewAny', [Asset::class, $client]),
            'viewPosts' => $user->can('viewAny', [Post::class, $client]),
            'useAi' => self::canUseAi($user),
            'createInvoice' => $user->can('create', [Invoice::class, $client]),
            'createSocialAccount' => $user->can('create', [SocialAccount::class, $client]),
            'createCampaign' => $user->can('create', [Campaign::class, $client]),
            'createAsset' => $user->can('create', [Asset::class, $client]),
            'createPost' => $user->can('create', [Post::class, $client]),
            'createInvitation' => $user->can('create', [ClientInvitation::class, $client]),
        ];

        $counts = Client::query()
            ->whereKey($client->id)
            ->withCount([
                'socialAccounts',
                'campaigns',
                'posts',
                'assets',
                'invoices',
                'invitations',
                'invitations as active_invitations_count' => fn (Builder $query) => $query
                    ->whereNotNull('accepted_at')
                    ->whereNull('revoked_at'),
                'aiTrends',
                'aiPostIdeas',
            ])
            ->firstOrFail();

        return [
            'client' => ClientPresenter::detail($client, $can['viewBilling']),
            'can' => $can,
            'tab' => $tab,
            'stats' => [
                'socialAccounts' => $can['viewSocialAccounts'] ? (int) $counts->social_accounts_count : 0,
                'campaigns' => $can['viewCampaigns'] ? (int) $counts->campaigns_count : 0,
                'posts' => $can['viewPosts'] ? (int) $counts->posts_count : 0,
                'assets' => $can['viewAssets'] ? (int) $counts->assets_count : 0,
                'billing' => $can['viewBilling'] ? [
                    'invoices' => (int) $counts->invoices_count,
                    'outstanding' => $this->outstanding($client),
                ] : null,
                'portalAccess' => $can['viewInvitations'] ? [
                    'invitations' => (int) $counts->invitations_count,
                    'active' => (int) $counts->getAttribute('active_invitations_count'),
                ] : null,
                'ai' => $can['useAi'] ? [
                    'trends' => (int) $counts->ai_trends_count,
                    'ideas' => (int) $counts->ai_post_ideas_count,
                    'briefs' => DailyBrief::query()->where('client_id', $client->id)->count(),
                ] : null,
            ],
        ];
    }

    /**
     * The AI tabs (Step 2.4/2.5): Owner/Strategist only, behind `config('ai.enabled')`.
     */
    public static function canUseAi(User $user): bool
    {
        return (bool) config('ai.enabled', false) && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Sent-but-unpaid totals, one per currency — never summed across currencies.
     *
     * @return list<array{currency: string, total: float}>
     */
    private function outstanding(Client $client): array
    {
        return array_values(Invoice::query()
            ->where('client_id', $client->id)
            ->where('status', InvoiceStatus::Sent)
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'total' => round((float) $row->total, 2)])
            ->all());
    }
}
