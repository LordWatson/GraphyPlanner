<?php

namespace Tests\Feature\Security;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\CampaignShareLink;
use App\Models\CampaignTemplate;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\ConnectInvite;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\SocialAccount;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Step I-1.9 — a generated cross-tenant test. For every route with a bound model parameter, the
 * models are created in organization B and the route is requested by every role of
 * organization A; each request must be refused (403/404). Built from `Route::getRoutes()`, so a
 * new route is covered the moment it's added — and one that forgets its `org_id` check fails here.
 */
class CrossTenantRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prefixes outside the org workspace, or bound by token/identity rather than an org-owned
     * model: preview (frozen fixture, read-only), the super admin console (cross-org on purpose),
     * auth flows, token links, and per-user records (notifications, passkeys).
     */
    private const SKIPPED_PREFIXES = [
        'preview', 'super-admin', 'email/verify', 'reset-password', 'review/', 'client-invite/',
        'staff-invite/', 'connect/', 'notifications/', 'user/passkeys', 'files/', 'storage/',
    ];

    /**
     * @var array<class-string<Model>, Model>
     */
    private array $foreign = [];

    public function test_every_bound_route_refuses_another_organizations_models(): void
    {
        Config::set('ai.enabled', true);

        $this->foreign = $this->buildForeignOrganization();
        $orgA = Organization::factory()->create();
        $clientA = Client::factory()->for($orgA, 'organization')->create();

        $users = collect([Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->map(fn (Role $role) => User::factory()->for($orgA, 'organization')->role($role)->create())
            ->push(User::factory()->for($orgA, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $clientA->id]));

        $checked = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $bindings = $this->boundModels($route);

            if ($bindings === null) {
                continue;
            }

            $method = collect($route->methods())->first(fn (string $m) => $m !== 'HEAD');
            $uri = '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) $bindings[$m[1]]->getRouteKey(), $route->uri()), '/');

            foreach ($users as $user) {
                $status = $this->actingAs($user)->json($method, $uri)->getStatusCode();
                $checked++;

                // `portal.redirect` sends a client contact to their own portal before anything
                // else runs — a redirect away, not access.
                $redirectedToPortal = $status === 302
                    && $user->role === Role::ClientReviewer
                    && in_array('portal.redirect', $route->gatherMiddleware(), true);

                if (! in_array($status, [403, 404], true) && ! $redirectedToPortal) {
                    $failures[] = "{$method} {$uri} as {$user->role->value} → {$status}";
                }
            }
        }

        $this->assertGreaterThan(300, $checked, 'Expected to exercise every bound route for every role.');
        $this->assertSame([], $failures, "Cross-tenant requests were not refused:\n".implode("\n", $failures));
    }

    /**
     * The org-B model for each of the route's bound parameters, or null when the route isn't one
     * this test covers.
     *
     * @return array<string, Model>|null
     */
    private function boundModels(RoutingRoute $route): ?array
    {
        $uri = $route->uri();

        foreach (self::SKIPPED_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return null;
            }
        }

        $parameters = $route->signatureParameters(['subClass' => Model::class]);

        if ($parameters === [] || ! in_array('web', $route->gatherMiddleware(), true)) {
            return null;
        }

        $bindings = [];

        foreach ($parameters as $parameter) {
            $class = $parameter->getType()?->getName();

            if (! isset($this->foreign[$class])) {
                $this->fail("No org-B fixture for {$class} (route {$uri}). Add one to buildForeignOrganization().");
            }

            $bindings[$parameter->getName()] = $this->foreign[$class];
        }

        // Route parameters can be named differently from the controller argument (e.g.
        // `{staff}` → `User $user`); map any leftover placeholder by position.
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        foreach ($matches[1] as $index => $name) {
            if (! isset($bindings[$name])) {
                $bindings[$name] = array_values($bindings)[$index] ?? null;
            }

            if ($bindings[$name] === null) {
                return null;
            }
        }

        return $bindings;
    }

    /**
     * One of everything, all inside organization B (and one client of it).
     *
     * @return array<class-string<Model>, Model>
     */
    private function buildForeignOrganization(): array
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);
        $generation = AiImageGeneration::factory()->create(['org_id' => $org->id, 'client_id' => $client->id]);
        $trend = AiTrend::create(['org_id' => $org->id, 'client_id' => $client->id, 'title' => 'Trend', 'summary' => 'Summary', 'platforms' => ['instagram'], 'confidence' => 0.5]);
        $idea = AiPostIdea::create(['org_id' => $org->id, 'client_id' => $client->id, 'title' => 'Idea', 'description' => 'Description', 'platforms' => ['instagram']]);
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        return [
            Organization::class => $org,
            User::class => $owner,
            Client::class => $client,
            Post::class => $post,
            PostActivityLog::class => PostActivityLog::factory()->create(['post_id' => $post->id, 'to_status' => PostStatus::WaitingClient]),
            Campaign::class => $campaign,
            CampaignPhase::class => CampaignPhase::factory()->create(['campaign_id' => $campaign->id]),
            CampaignDeliverable::class => CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id]),
            CampaignShareLink::class => CampaignShareLink::query()->create(['campaign_id' => $campaign->id, 'token_hash' => hash('sha256', 'foreign'), 'expires_at' => now()->addDay()]),
            CampaignTemplate::class => CampaignTemplate::query()->withoutGlobalScopes()->create(['org_id' => $org->id, 'name' => 'Their template', 'duration_days' => 14, 'phases' => [], 'deliverables' => []]),
            Asset::class => Asset::factory()->for($client)->create(['org_id' => $org->id]),
            SocialAccount::class => SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::Instagram]),
            ConnectInvite::class => ConnectInvite::query()->withoutGlobalScopes()->create(['org_id' => $org->id, 'client_id' => $client->id, 'token_hash' => hash('sha256', 'foreign-connect'), 'expires_at' => now()->addDay()]),
            Invoice::class => $invoice,
            InvoiceItem::class => InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'org_id' => $org->id]),
            ClientInvitation::class => ClientInvitation::factory()->create(['client_id' => $client->id]),
            StaffInvitation::class => StaffInvitation::factory()->create(['org_id' => $org->id]),
            OrganizationReport::class => OrganizationReport::factory()->create(['org_id' => $org->id, 'created_by' => $owner->id]),
            AiTrend::class => $trend,
            AiPostIdea::class => $idea,
            AiImageGeneration::class => $generation,
            AiImageVariant::class => AiImageVariant::factory()->create(['generation_id' => $generation->id, 'org_id' => $org->id]),
        ];
    }
}
