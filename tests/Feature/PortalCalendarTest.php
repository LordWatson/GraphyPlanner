<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $reviewer;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['default_timezone' => 'UTC']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);
        $this->account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'timezone' => 'UTC',
        ]);
    }

    private function scheduledPost(Client $client, SocialAccount $account, PostStatus $status, array $attributes = []): Post
    {
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => $status, ...$attributes]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => '2026-10-20',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-20T09:00:00+00:00',
        ]);

        return $post;
    }

    public function test_client_reviewer_sees_only_their_own_clients_posts(): void
    {
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $otherAccount = SocialAccount::factory()->for($otherClient)->create(['org_id' => $this->org->id, 'timezone' => 'UTC']);

        $own = $this->scheduledPost($this->client, $this->account, PostStatus::Scheduled);
        $this->scheduledPost($otherClient, $otherAccount, PostStatus::Scheduled);

        $this->actingAs($this->reviewer)
            ->get(route('portal.calendar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/calendar')
                ->has('occurrences', 1)
                ->where('occurrences.0.post_id', $own->id)
                ->where('occurrences.0.account_local.time', '09:00')
                ->where('filterOptions.clients', [])
            );
    }

    public function test_pre_review_internal_statuses_never_appear(): void
    {
        $visible = $this->scheduledPost($this->client, $this->account, PostStatus::WaitingClient);

        foreach ([PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview] as $internal) {
            $this->scheduledPost($this->client, $this->account, $internal);
        }

        $this->actingAs($this->reviewer)
            ->get(route('portal.calendar'))
            ->assertInertia(fn ($page) => $page
                ->has('occurrences', 1)
                ->where('occurrences.0.post_id', $visible->id)
                ->has('filterOptions.statuses', count(PostStatus::clientVisible()))
            );
    }

    public function test_an_internal_status_filter_is_ignored_rather_than_honoured(): void
    {
        $this->scheduledPost($this->client, $this->account, PostStatus::Draft);
        $scheduled = $this->scheduledPost($this->client, $this->account, PostStatus::Scheduled);

        $this->actingAs($this->reviewer)
            ->get(route('portal.calendar', ['status' => 'draft']))
            ->assertInertia(fn ($page) => $page
                ->has('occurrences', 1)
                ->where('occurrences.0.post_id', $scheduled->id)
                ->where('filters.status', null)
            );
    }

    public function test_it_filters_by_campaign_and_only_offers_the_clients_own_campaigns(): void
    {
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        Campaign::factory()->create(['org_id' => $this->org->id]);

        $inCampaign = $this->scheduledPost($this->client, $this->account, PostStatus::Scheduled, ['campaign_id' => $campaign->id]);
        $this->scheduledPost($this->client, $this->account, PostStatus::Scheduled);

        $this->actingAs($this->reviewer)
            ->get(route('portal.calendar', ['campaign_id' => $campaign->id]))
            ->assertInertia(fn ($page) => $page
                ->has('occurrences', 1)
                ->where('occurrences.0.post_id', $inCampaign->id)
                ->has('filterOptions.campaigns', 1)
                ->where('filterOptions.campaigns.0.id', $campaign->id)
            );
    }

    public function test_staff_roles_cannot_open_the_portal_calendar(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('portal.calendar'))->assertForbidden();
    }
}
