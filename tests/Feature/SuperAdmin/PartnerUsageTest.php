<?php

namespace Tests\Feature\SuperAdmin;

use App\Actions\SuperAdmin\GetPartnerUsageAction;
use App\Enums\ApprovalDecision;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostApproval;
use App\Models\ProductFeedback;
use App\Models\User;
use App\Support\Analytics\AnalyticsRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Step M-8.2 — the design-partner usage view: per-partner weekly active users, posts published,
 * approvals, invoices sent and AI calls, and the super-admin design-partner flag.
 */
class PartnerUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 12:00:00');

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function transition(Client $client, PostStatus $status, int $daysAgo): void
    {
        $post = Post::factory()->for($client)->create(['status' => $status]);

        PostActivityLog::factory()->create([
            'post_id' => $post->id,
            'user_id' => null,
            'from_status' => $status === PostStatus::Approved ? PostStatus::WaitingClient : PostStatus::Publishing,
            'to_status' => $status,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * An approval decision on a fresh post. A null `$actor` is the unauthenticated review portal
     * (a client decision); otherwise the actor's role decides client vs internal.
     */
    private function approval(Client $client, ?User $actor, int $daysAgo): void
    {
        $post = Post::factory()->for($client)->create(['status' => PostStatus::Approved]);

        PostApproval::factory()->create([
            'post_id' => $post->id,
            'user_id' => $actor?->id,
            'decision' => ApprovalDecision::Approved,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function invoiceSent(Client $client, int $issuedDaysAgo): void
    {
        Invoice::factory()->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'amount' => 500,
            'currency' => 'GBP',
            'issue_date' => now()->subDays($issuedDaysAgo)->toDateString(),
        ]);
    }

    private function aiCall(Organization $org, Client $client, bool $succeeded): void
    {
        AiUsageLog::create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'user_id' => null,
            'provider' => 'deepseek',
            'model' => 'test-model',
            'method' => 'text.caption',
            'succeeded' => $succeeded,
            'error_code' => $succeeded ? null : 'provider_error',
            'cost_estimate' => 0.01,
        ]);
    }

    public function test_the_usage_view_reports_each_partners_numbers(): void
    {
        $partner = Organization::factory()->designPartner()->create(['name' => 'Northlight Studio']);

        // Weekly active: one active in the last 7 days, one stale, the owner never active → WAU = 1.
        User::factory()->for($partner, 'organization')->role(Role::Owner)->create(['last_active_at' => null]);
        User::factory()->for($partner, 'organization')->role(Role::Strategist)->create(['last_active_at' => now()->subDays(1)]);
        User::factory()->for($partner, 'organization')->role(Role::Designer)->create(['last_active_at' => now()->subDays(10)]);

        $client = Client::factory()->for($partner, 'organization')->create();
        $this->transition($client, PostStatus::Published, 2);
        $this->transition($client, PostStatus::Published, 5);
        $this->transition($client, PostStatus::Published, 60); // outside the 30-day range

        // Approvals split by who signed off: a client reviewer + the review portal (null actor) =
        // two client approvals; an Owner override = one internal approval.
        $reviewer = User::factory()->for($partner, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);
        $owner = User::factory()->for($partner, 'organization')->role(Role::Owner)->create();
        $this->approval($client, $reviewer, 3);
        $this->approval($client, null, 4);
        $this->approval($client, $owner, 6);
        $this->approval($client, $reviewer, 90); // outside the 30-day range

        $this->invoiceSent($client, 10);
        $this->invoiceSent($client, 20);
        $this->aiCall($partner, $client, true);
        $this->aiCall($partner, $client, false);

        // A non-partner org with plenty of activity — must never reach the view.
        $other = Organization::factory()->create(['name' => 'Not A Partner']);
        $otherClient = Client::factory()->for($other, 'organization')->create();
        $this->transition($otherClient, PostStatus::Published, 1);
        $this->invoiceSent($otherClient, 1);

        $usage = app(GetPartnerUsageAction::class)(AnalyticsRange::fromArray(['range' => '30d']));

        $this->assertCount(1, $usage['partners']);

        $row = $usage['partners'][0];
        $this->assertSame($partner->id, $row['id']);
        $this->assertSame('Northlight Studio', $row['name']);
        $this->assertSame(1, $row['weekly_active_users']);
        $this->assertSame(5, $row['members']);
        $this->assertSame(2, $row['posts_published']);
        $this->assertSame(3, $row['approvals']);
        $this->assertSame(2, $row['client_approvals']);
        $this->assertSame(1, $row['internal_approvals']);
        $this->assertSame(2, $row['invoices_sent']);
        $this->assertSame(2, $row['ai_calls']);

        $this->assertSame([
            'partners' => 1,
            'weekly_active_users' => 1,
            'posts_published' => 2,
            'approvals' => 3,
            'client_approvals' => 2,
            'internal_approvals' => 1,
            'invoices_sent' => 2,
            'ai_calls' => 2,
        ], $usage['totals']);
    }

    public function test_the_partners_page_renders_the_usage_and_recent_feedback(): void
    {
        $partner = Organization::factory()->designPartner()->create(['name' => 'Harbour & Co']);
        $staff = User::factory()->for($partner, 'organization')->role(Role::Owner)->create();
        ProductFeedback::factory()->create([
            'org_id' => $partner->id,
            'user_id' => $staff->id,
            'message' => 'The calendar is brilliant.',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.partners.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/partners/index')
                ->where('usage.totals.partners', 1)
                ->where('usage.partners.0.name', 'Harbour & Co')
                ->where('recentFeedback.0.message', 'The calendar is brilliant.')
                ->has('ranges'));
    }

    public function test_the_range_filter_narrows_the_activity_window(): void
    {
        $partner = Organization::factory()->designPartner()->create();
        $client = Client::factory()->for($partner, 'organization')->create();
        $this->transition($client, PostStatus::Published, 2);
        $this->transition($client, PostStatus::Published, 20); // inside 30d, outside 7d

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.partners.index', ['range' => '7d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('usage.range.range', '7d')
                ->where('usage.partners.0.posts_published', 1));
    }

    public function test_a_super_admin_can_flag_an_organization_as_a_design_partner(): void
    {
        $organization = Organization::factory()->create();
        $this->assertFalse($organization->is_design_partner);

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.organizations.update', $organization), [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'default_timezone' => 'UTC',
                'default_currency' => 'GBP',
                'is_design_partner' => '1',
            ])
            ->assertRedirect(route('super-admin.organizations.show', $organization));

        $this->assertTrue($organization->fresh()->is_design_partner);
        $this->assertTrue(Organization::query()->designPartners()->whereKey($organization->id)->exists());
    }

    public function test_the_design_partner_flag_can_be_turned_off(): void
    {
        $organization = Organization::factory()->designPartner()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.organizations.update', $organization), [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'default_timezone' => 'UTC',
                'default_currency' => 'GBP',
                'is_design_partner' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse($organization->fresh()->is_design_partner);
    }
}
