<?php

namespace Tests\Feature\Content;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-5.1 — bulk actions from the content hub: move, archive, add to campaign and reschedule,
 * each re-validated per post and reporting anything it has to skip.
 */
class ContentHubBulkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            ...$attributes,
        ]);
    }

    public function test_a_bulk_move_transitions_what_it_can_and_reports_the_rest(): void
    {
        $draft = $this->makePost(['status' => PostStatus::Draft]);
        $published = $this->makePost(['status' => PostStatus::Published]);

        $response = $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'move',
            'post_ids' => [$draft->id, $published->id],
            'value' => 'internal_review',
        ])->assertOk();

        $response->assertJsonPath('done', [$draft->id]);
        $this->assertSame($published->id, $response->json('skipped.0.id'));
        $this->assertSame(PostStatus::InternalReview, $draft->refresh()->status);
        $this->assertSame(PostStatus::Published, $published->refresh()->status);
    }

    public function test_a_bulk_archive_moves_posts_to_archived(): void
    {
        $post = $this->makePost(['status' => PostStatus::Draft]);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'archive',
            'post_ids' => [$post->id],
        ])->assertOk()->assertJsonPath('done', [$post->id]);

        $this->assertSame(PostStatus::Archived, $post->refresh()->status);
    }

    public function test_adding_to_a_campaign_rejects_another_clients_campaign(): void
    {
        $post = $this->makePost(['status' => PostStatus::Draft]);

        $ownCampaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $otherCampaign = Campaign::factory()->for($otherClient)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'campaign',
            'post_ids' => [$post->id],
            'value' => $otherCampaign->id,
        ])->assertOk()->assertJsonPath('done', []);

        $this->assertNull($post->refresh()->campaign_id);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'campaign',
            'post_ids' => [$post->id],
            'value' => $ownCampaign->id,
        ])->assertOk()->assertJsonPath('done', [$post->id]);

        $this->assertSame($ownCampaign->id, $post->refresh()->campaign_id);
    }

    public function test_a_bulk_reschedule_shifts_each_targets_date(): void
    {
        $post = $this->makePost(['status' => PostStatus::Draft]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDays(3)->toDateString(),
            'scheduled_local_time' => '09:00:00',
        ]);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'reschedule',
            'post_ids' => [$post->id],
            'value' => 7,
        ])->assertOk()->assertJsonPath('done', [$post->id]);

        $this->assertSame(
            now()->addDays(10)->toDateString(),
            $target->refresh()->scheduled_local_date->toDateString(),
        );
    }

    public function test_bulk_never_touches_another_orgs_posts(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $otherPost = Post::factory()->for($otherClient)->create([
            'org_id' => $otherOrg->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'move',
            'post_ids' => [$otherPost->id],
            'value' => 'internal_review',
        ])->assertOk()->assertJsonPath('done', []);

        $this->assertSame(PostStatus::Draft, $otherPost->refresh()->status);
    }

    public function test_a_viewer_cannot_move_posts(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();
        $post = $this->makePost(['status' => PostStatus::Draft]);

        $this->actingAs($viewer)->postJson(route('content.bulk'), [
            'action' => 'move',
            'post_ids' => [$post->id],
            'value' => 'internal_review',
        ])->assertOk()->assertJsonPath('done', []);

        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }

    public function test_a_client_reviewer_is_kept_out_of_bulk(): void
    {
        $reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);
        $post = $this->makePost(['status' => PostStatus::Draft]);

        // `portal.redirect` keeps a reviewer inside `/portal/*` — the bulk endpoint never runs.
        $this->actingAs($reviewer)->post(route('content.bulk'), [
            'action' => 'move',
            'post_ids' => [$post->id],
            'value' => 'internal_review',
        ])->assertRedirect(route('portal.dashboard'));

        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }
}
