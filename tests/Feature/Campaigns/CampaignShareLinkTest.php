<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignShareLink;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-5.4 — shareable preview links: hashed at rest, expiring, revocable,
 * read-only, and only posts from "Approved" onward.
 */
class CampaignShareLinkTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->campaign = $this->makeCampaign(['name' => 'Spring launch', 'status' => CampaignStatus::Active, 'notes' => 'Margin is thin.']);
    }

    /**
     * @return array{url: string, token: string, id: int}
     */
    private function makeLink(int $days = 14): array
    {
        $response = $this->actingAs($this->strategist())->postJson(route('campaigns.share-links.store', $this->campaign), ['days' => $days])->assertCreated();
        $url = (string) $response->json('url');

        return ['url' => $url, 'token' => basename((string) parse_url($url, PHP_URL_PATH)), 'id' => (int) $response->json('id')];
    }

    public function test_the_token_is_hashed_at_rest_and_shown_once(): void
    {
        ['token' => $token, 'id' => $id] = $this->makeLink();

        $link = CampaignShareLink::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertDatabaseMissing('campaign_share_links', ['token_hash' => $token]);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $this->campaign->id, 'event' => 'share_link.created']);

        $this->actingAs($this->strategist())->get(route('campaigns.show', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page->has('shareLinks', 1)->missing('shareLinks.0.token')->missing('shareLinks.0.token_hash'));
    }

    public function test_the_preview_shows_only_approved_onward_posts_and_no_internal_detail(): void
    {
        foreach ([PostStatus::Draft, PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::ChangesRequested, PostStatus::Approved, PostStatus::Scheduled, PostStatus::Published] as $status) {
            Post::factory()->for($this->client)->create(['campaign_id' => $this->campaign->id, 'status' => $status, 'master_caption' => "Caption {$status->value}"]);
        }
        ['url' => $url] = $this->makeLink();
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('share/campaign')
                ->where('campaign.name', 'Spring launch')
                ->where('posts', fn ($posts) => collect($posts)->pluck('status')->sort()->values()->all() === ['approved', 'published', 'scheduled'])
                ->missing('campaign.notes')
                ->missing('campaign.billing'))
            ->assertDontSee('Margin is thin.')
            ->assertDontSee('Caption draft');

        $this->assertSame(1, CampaignShareLink::query()->firstOrFail()->view_count);
    }

    public function test_an_expired_link_shows_nothing(): void
    {
        ['url' => $url] = $this->makeLink(days: 1);
        auth()->logout();

        $this->travel(2)->days();

        $this->get($url)->assertNotFound();
    }

    public function test_a_revoked_link_shows_nothing(): void
    {
        ['url' => $url, 'id' => $id] = $this->makeLink();

        $this->actingAs($this->owner())->delete(route('campaigns.share-links.destroy', [$this->campaign, $id]))->assertSessionHasNoErrors();
        $this->assertNotNull(CampaignShareLink::query()->findOrFail($id)->revoked_at);
        auth()->logout();

        $this->get($url)->assertNotFound();
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('campaign-share.show', str_repeat('x', 64)))->assertNotFound();
    }

    public function test_the_preview_accepts_no_decisions(): void
    {
        ['url' => $url] = $this->makeLink();
        auth()->logout();

        $this->post($url)->assertStatus(405);
    }

    public function test_designers_and_viewers_cannot_make_or_revoke_links(): void
    {
        ['id' => $id] = $this->makeLink();

        foreach ([$this->designer(), $this->viewer()] as $user) {
            $this->actingAs($user)->postJson(route('campaigns.share-links.store', $this->campaign))->assertForbidden();
            $this->actingAs($user)->delete(route('campaigns.share-links.destroy', [$this->campaign, $id]))->assertForbidden();
        }

        $this->actingAs($this->designer())->get(route('campaigns.show', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page->where('shareLinks', []));
    }

    public function test_a_link_cannot_be_revoked_through_another_campaign(): void
    {
        ['id' => $id] = $this->makeLink();
        $other = $this->makeCampaign();

        $this->actingAs($this->owner())->delete(route('campaigns.share-links.destroy', [$other, $id]))->assertNotFound();
        $this->assertNull(CampaignShareLink::query()->findOrFail($id)->revoked_at);
    }
}
