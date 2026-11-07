<?php

namespace Tests\Feature\Security;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignActivityLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\StaffActivityLog;
use App\Models\User;
use App\Services\Security\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Show me everything this person did" — the question an auditor, a client's procurement team and
 * every security questionnaire asks, and which four separate per-screen activity tables could not
 * answer.
 *
 * The tests pin the things that make a trail worth having: it reaches every source, it never leaks
 * another organisation's activity, and the file the owner downloads holds what the screen showed.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['default_timezone' => 'UTC']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create(['name' => 'Ada']);
    }

    private function trail(?int $actorUserId = null): array
    {
        return app(AuditTrail::class)->forOrganization(
            $this->org,
            CarbonImmutable::now()->subDays(30),
            CarbonImmutable::now()->addDay(),
            $actorUserId,
        )->all();
    }

    private function staffChange(?User $actor = null): StaffActivityLog
    {
        return StaffActivityLog::factory()->create([
            'org_id' => $this->org->id,
            'actor_user_id' => ($actor ?? $this->owner)->id,
            'user_id' => User::factory()->for($this->org, 'organization')->create(['name' => 'Grace', 'email' => 'grace@example.com'])->id,
            'action' => 'role_changed',
            'from_role' => Role::Viewer,
            'to_role' => Role::Strategist,
        ]);
    }

    private function postApproval(?User $actor = null): PostActivityLog
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme']);
        $post = Post::factory()->for($client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Approved]);

        return PostActivityLog::factory()->create([
            'post_id' => $post->id,
            'user_id' => ($actor ?? $this->owner)->id,
            'from_status' => PostStatus::WaitingClient,
            'to_status' => PostStatus::Approved,
        ]);
    }

    public function test_it_reads_every_source_it_claims_to(): void
    {
        $this->staffChange();
        $this->postApproval();

        $client = Client::factory()->for($this->org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $this->org->id, 'name' => 'Spring']);
        CampaignActivityLog::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'event' => 'status.changed',
            'from' => 'draft',
            'to' => 'live',
        ]);

        PlanAuditLog::factory()->create([
            'org_id' => $this->org->id,
            'actor_user_id' => $this->owner->id,
        ]);

        $sources = array_column($this->trail(), 'source');

        foreach (array_values(AuditTrail::SOURCES) as $source) {
            $this->assertContains($source, $sources, "The trail never produced a [{$source}] entry.");
        }
    }

    public function test_a_staff_role_change_names_who_changed_whom(): void
    {
        $this->staffChange();

        $entry = collect($this->trail())->firstWhere('source', 'staff');

        $this->assertSame('role_changed', $entry['action']);
        $this->assertSame('Ada', $entry['actor']);
        $this->assertStringContainsString('grace@example.com', $entry['subject']);
        $this->assertSame('from viewer · to strategist', $entry['detail']);
    }

    public function test_entries_come_back_newest_first(): void
    {
        $old = $this->staffChange();
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $recent = $this->postApproval();
        $recent->forceFill(['created_at' => now()->subHour()])->save();

        $this->assertSame(['post', 'staff'], array_column($this->trail(), 'source'));
    }

    public function test_the_window_excludes_what_happened_outside_it(): void
    {
        $this->staffChange()->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->assertSame([], $this->trail());
    }

    public function test_filtering_by_person_keeps_only_their_actions(): void
    {
        $other = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $this->staffChange($this->owner);
        $this->postApproval($other);

        $entries = $this->trail($other->id);

        $this->assertCount(1, $entries);
        $this->assertSame('post', $entries[0]['source']);
    }

    /**
     * The one failure that would make this feature worse than not having it.
     */
    public function test_another_organizations_activity_never_appears(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create(['name' => 'Someone else']);
        StaffActivityLog::factory()->create([
            'org_id' => $otherOrg->id,
            'actor_user_id' => $theirOwner->id,
            'user_id' => $theirOwner->id,
        ]);
        $theirClient = Client::factory()->for($otherOrg, 'organization')->create();
        $theirPost = Post::factory()->for($theirClient)->create(['org_id' => $otherOrg->id]);
        PostActivityLog::factory()->create(['post_id' => $theirPost->id, 'user_id' => $theirOwner->id]);
        $theirCampaign = Campaign::factory()->for($theirClient)->create(['org_id' => $otherOrg->id]);
        CampaignActivityLog::create(['campaign_id' => $theirCampaign->id, 'user_id' => $theirOwner->id, 'event' => 'status.changed']);
        PlanAuditLog::factory()->create(['org_id' => $otherOrg->id, 'actor_user_id' => $theirOwner->id]);

        $this->assertSame([], $this->trail());
    }

    /**
     * The union is a deliberate shortcut over four existing tables. It only stays honest if a new
     * log table forces a decision, so this fails until the table is either read or listed as
     * deliberately not audited.
     */
    public function test_every_log_table_is_either_read_or_explicitly_excluded(): void
    {
        $connection = DB::connection();
        $tables = $connection->getDriverName() === 'sqlite'
            ? Schema::getTableListing()
            : Schema::getTableListing($connection->getDatabaseName());

        $known = array_merge(array_keys(AuditTrail::SOURCES), array_keys(AuditTrail::NOT_AUDITED));
        $found = [];

        foreach ($tables as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (! str_ends_with($table, '_activity_logs') && ! str_ends_with($table, '_audit_logs')) {
                continue;
            }

            $found[] = $table;

            $this->assertContains(
                $table,
                $known,
                "[{$table}] is neither read by AuditTrail::SOURCES nor listed in AuditTrail::NOT_AUDITED with a reason.",
            );
        }

        // Without this the guard passes by finding nothing, which is how a drift guard dies quietly.
        $this->assertNotEmpty(array_intersect($found, array_keys(AuditTrail::SOURCES)));
    }

    // --- the page and the export ---------------------------------------------------------------

    public function test_an_owner_sees_the_log(): void
    {
        $this->staffChange();

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/audit-log')
                ->where('total', 1)
                ->where('entries.0.source', 'staff')
                ->where('entries.0.actor', 'Ada'));
    }

    public function test_a_teammate_cannot_see_it(): void
    {
        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($strategist)->get(route('audit-log.index'))->assertForbidden();
    }

    public function test_the_export_holds_what_the_screen_showed(): void
    {
        $this->staffChange();

        $response = $this->confirmPassword()->actingAs($this->owner)->get(route('audit-log.export'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Action', $csv);
        $this->assertStringContainsString('role_changed', $csv);
        $this->assertStringContainsString('Ada', $csv);
    }

    /**
     * The export is the whole organisation's activity in one file, so it re-checks the password the
     * same way the analytics and invoice exports do.
     */
    public function test_the_export_asks_for_the_password_again(): void
    {
        $this->actingAs($this->owner)->get(route('audit-log.export'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_a_person_filter_that_is_not_a_member_is_refused(): void
    {
        $outsider = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', ['actor' => $outsider->id]))
            ->assertSessionHasErrors('actor');
    }

    /**
     * An audit read in UTC while the agency works in Sydney puts actions on the wrong day, which is
     * exactly the kind of mistake an audit is supposed to settle.
     */
    public function test_times_are_shown_in_the_organizations_timezone(): void
    {
        $this->org->update(['default_timezone' => 'Australia/Sydney']);
        $this->staffChange()->forceFill(['created_at' => '2026-03-01 22:30:00'])->save();

        $this->actingAs($this->owner)->get(route('audit-log.index', ['from' => '2026-03-01', 'to' => '2026-03-03']))
            ->assertOk()
            // 22:30 UTC on the 1st is 09:30 on the 2nd in Sydney.
            ->assertInertia(fn ($page) => $page->where('entries.0.at', '2026-03-02 09:30'));
    }
}
