<?php

namespace App\Models\Scopes;

use App\Models\AdAccount;
use App\Models\AiImageGeneration;
use App\Models\AiPostIdea;
use App\Models\AiTextJob;
use App\Models\AiTrend;
use App\Models\Asset;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\BioPage;
use App\Models\BrandBrain;
use App\Models\BrandDeal;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientHashtag;
use App\Models\ClientHealthHistory;
use App\Models\ClientInvitation;
use App\Models\ClientReport;
use App\Models\ConnectInvite;
use App\Models\DailyBrief;
use App\Models\GridPlan;
use App\Models\InboxConversation;
use App\Models\Invoice;
use App\Models\McpUploadSession;
use App\Models\MediaKit;
use App\Models\Post;
use App\Models\Product;
use App\Models\ReviewToken;
use App\Models\SavedReply;
use App\Models\SocialAccount;
use App\Models\TrackedProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The second half of tenant isolation: inside one organization, limit a restricted staff member to
 * the clients they have been put on.
 *
 * `OrganizationScope` keeps organization A out of organization B. This keeps a freelance Designer
 * hired for one brand out of the other nine on the same roster — brand brains, captions, analytics,
 * invoices, inbox threads and media alike. It only does anything when the organization has turned
 * `restrict_staff_to_assigned_clients` on, and never applies to an Owner or a super admin.
 *
 * Like `OrganizationScope` this is the safety net for *queries*: a list or lookup that forgets to
 * filter cannot return another client's rows. Single records resolved by route-model binding are
 * decided by the `Gate::before` in `AppServiceProvider`, because the binding deliberately resolves
 * without scopes so the policy can answer 403.
 *
 * @implements Scope<Model>
 */
class ClientAccessScope implements Scope
{
    /**
     * Every model whose rows belong to one client. Registered in `AppServiceProvider::boot()`.
     *
     * Listed per model rather than hooked into `BelongsToOrganization`, because several of these
     * (`ClientHashtag`, `ClientHealthHistory`, `ReviewToken`) do not use that trait — hooking it
     * would have silently missed them. A list rather than schema introspection because it costs
     * nothing at runtime and can be reviewed.
     *
     * `ClientAccessScopeTest` reads the schema and fails if a table with a `client_id` column is
     * neither covered here nor exempted below. That matters more than usual: the failure mode of a
     * missing entry is a confidentiality hole that looks like nothing at all.
     *
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        Client::class,
        AdAccount::class,
        AiImageGeneration::class,
        AiPostIdea::class,
        AiTextJob::class,
        AiTrend::class,
        Asset::class,
        AudienceBroadcast::class,
        AudienceContact::class,
        BioPage::class,
        BrandBrain::class,
        BrandDeal::class,
        Campaign::class,
        ClientContact::class,
        ClientHashtag::class,
        ClientHealthHistory::class,
        ClientInvitation::class,
        ClientReport::class,
        ConnectInvite::class,
        DailyBrief::class,
        GridPlan::class,
        InboxConversation::class,
        Invoice::class,
        McpUploadSession::class,
        MediaKit::class,
        Post::class,
        Product::class,
        ReviewToken::class,
        SavedReply::class,
        SocialAccount::class,
        TrackedProfile::class,
    ];

    /**
     * Tables with a `client_id` column that are deliberately **not** restricted, and why. Written
     * down rather than remembered, and checked by the same test.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        // A client reviewer's own account row. Their access is already pinned to one client by
        // `EnsureClientPortalAccess`, and scoping `users` would break the staff roster and recurse
        // through `accessibleClientIds()`, which reads a user to find out what it may read.
        'users' => 'portal reviewer accounts; pinned by EnsureClientPortalAccess',

        // Org-level accounting that happens to record which client a call was for. Scoping it would
        // make a restricted member's AI credit check count only their own clients' usage, so they
        // would be handed credits the organization has already spent.
        'ai_usage_logs' => 'org-wide AI metering, not per-client content',

        // The assignment table itself. Scoping it would be circular: it is what the scope reads to
        // decide what may be read.
        'client_user' => 'the assignments the scope is built from',
    ];

    /**
     * `clients` is keyed on its own `id`; everything else names the client in `client_id`.
     */
    private const COLUMNS = ['clients' => 'id'];

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $allowed = self::accessibleClientIds();

        if ($allowed === null) {
            return;
        }

        if ($allowed === []) {
            // Assigned to nothing means exactly that — not "everything".
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = self::COLUMNS[$model->getTable()] ?? 'client_id';

        $builder->whereIn($model->qualifyColumn($column), $allowed);
    }

    /**
     * The signed-in user's accessible client ids, or null when no restriction applies (including
     * when there is no signed-in user at all — queue workers, scheduled commands, the webhook and
     * the token-based review pages, exactly as `OrganizationScope` treats them).
     *
     * @return list<int>|null
     */
    public static function accessibleClientIds(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->accessibleClientIds() : null;
    }

    /**
     * Whether `$user` may reach `$client`. The question the `Gate::before` asks.
     */
    public static function allows(User $user, ?Client $client): bool
    {
        return self::allowsClientId($user, $client?->id);
    }

    /**
     * The same question for a record that only carries the id.
     *
     * A null id is allowed: it is an unsaved model — `authorize('create', new Client)` and the
     * like — which belongs to no client yet, so there is nothing to restrict. Denying it would
     * block every create check.
     */
    public static function allowsClientId(User $user, ?int $clientId): bool
    {
        if ($clientId === null) {
            return true;
        }

        $allowed = $user->accessibleClientIds();

        return $allowed === null || in_array($clientId, $allowed, true);
    }
}
