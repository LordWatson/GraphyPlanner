<?php

namespace Tests\Feature\Stories;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffRecipient;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-1.5 — the client approves a Story's link and stickers with the creative (review link and
 * portal), and an agency sets who posts those Stories by hand on the client.
 */
class StoryExtrasClientSurfacesTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-18 09:00:00');
        $this->setUpStoryHandoffs();
    }

    public function test_the_review_link_lists_the_storys_extras_read_only(): void
    {
        $post = $this->storyPost(PostStatus::WaitingClient, extras: [
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']],
            'placement_note' => 'Link bottom centre.',
        ]);
        ['token' => $token] = app(CreateReviewTokenAction::class)($this->client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('review/show')
                ->where('post.targets.0.story.summary', 'a link and a poll')
                ->where('post.targets.0.story.posted_by', 'team')
                ->where('post.targets.0.story.placement_note', 'Link bottom centre.')
                ->where('post.targets.0.story.extras.0.label', 'Link sticker')
                ->where('post.targets.0.story.extras.0.value', 'https://example.com/offer')
                ->where('post.targets.0.story.extras.1.value', 'Shop now')
                ->where('post.targets.0.story.extras.2.value', 'Which one?'));
    }

    public function test_the_review_link_says_when_the_client_posts_it_themselves(): void
    {
        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client]);
        $post = $this->storyPost(PostStatus::WaitingClient);
        ['token' => $token] = app(CreateReviewTokenAction::class)($this->client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('post.targets.0.story.posted_by', 'client'));
    }

    public function test_a_plain_story_and_a_feed_channel_carry_no_story_note(): void
    {
        $post = $this->storyPost(PostStatus::WaitingClient, extras: []);
        $this->addFeedChannel($post);
        ['token' => $token] = app(CreateReviewTokenAction::class)($this->client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('post.targets.0.story', null)
                ->where('post.targets.1.story', null));
    }

    public function test_a_review_token_never_shows_another_clients_extras(): void
    {
        $other = Client::factory()->for($this->org, 'organization')->create();
        $theirs = $this->storyPost(PostStatus::WaitingClient, extras: $this->linkExtras('https://example.com/theirs'));
        $ours = $this->storyPost(PostStatus::WaitingClient, extras: $this->linkExtras('https://example.com/ours'));
        $theirs->update(['client_id' => $other->id]);
        ['token' => $token] = app(CreateReviewTokenAction::class)($this->client, $ours);

        $response = $this->get(route('review.show', $token))->assertOk();

        $response->assertSee('example.com\/ours', false);
        $response->assertDontSee('example.com\/theirs', false);
    }

    public function test_the_portal_lists_the_extras_for_the_reviewers_own_client_only(): void
    {
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $post = $this->storyPost(PostStatus::WaitingClient);

        $this->actingAs($reviewer)->get(route('portal.posts.show', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/posts/show')
                ->where('post.targets.0.story.extras.0.value', 'https://example.com/offer')
                ->where('post.targets.0.story.posted_by', 'team'));

        $other = Client::factory()->for($this->org, 'organization')->create();
        $outsider = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $other->id]);

        $this->actingAs($outsider)->get(route('portal.posts.show', $post))->assertNotFound();
    }

    public function test_an_owner_or_strategist_sets_who_posts_a_clients_stories(): void
    {
        foreach ([Role::Owner, Role::Strategist] as $role) {
            $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Team]);

            $this->actingAs($this->member($role))
                ->put(route('clients.update', $this->client), [
                    'name' => $this->client->name,
                    'status' => $this->client->status->value,
                    'story_handoff_recipient' => 'client',
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(StoryHandoffRecipient::Client, $this->client->refresh()->story_handoff_recipient);
        }
    }

    public function test_a_designer_or_viewer_cannot_change_who_posts_a_clients_stories(): void
    {
        foreach ([Role::Designer, Role::Viewer] as $role) {
            $this->actingAs($this->member($role))
                ->put(route('clients.update', $this->client), [
                    'name' => $this->client->name,
                    'status' => $this->client->status->value,
                    'story_handoff_recipient' => 'client',
                ])
                ->assertForbidden();
        }

        $this->assertSame(StoryHandoffRecipient::Team, $this->client->refresh()->story_handoff_recipient);
    }

    public function test_an_unknown_recipient_is_rejected_and_an_omitted_one_is_left_alone(): void
    {
        $owner = $this->member(Role::Owner);
        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client]);

        $this->actingAs($owner)
            ->put(route('clients.update', $this->client), [
                'name' => $this->client->name,
                'status' => $this->client->status->value,
                'story_handoff_recipient' => 'nobody',
            ])
            ->assertSessionHasErrors('story_handoff_recipient');

        $this->actingAs($owner)
            ->put(route('clients.update', $this->client), [
                'name' => $this->client->name,
                'status' => $this->client->status->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(StoryHandoffRecipient::Client, $this->client->refresh()->story_handoff_recipient);
    }

    public function test_the_client_form_only_offers_the_setting_while_the_lane_is_on(): void
    {
        $owner = $this->member(Role::Owner);

        $this->actingAs($owner)->get(route('clients.edit', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('client.story_handoff_recipient', 'team')
                ->has('storyHandoff.options', 2));

        config(['publishing.story_handoff.enabled' => false]);

        $this->actingAs($owner)->get(route('clients.edit', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('storyHandoff', null));
    }

    public function test_the_editor_is_told_who_can_post_a_story_by_hand(): void
    {
        $owner = $this->member(Role::Owner);
        $designer = $this->member(Role::Designer);
        $this->member(Role::Viewer);
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['created_by' => $designer->id]);

        $this->actingAs($owner)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storyHandoff.default_recipient', 'team')
                ->where('storyHandoff.can_choose_recipient', true)
                ->where('storyHandoff.creator_id', $designer->id)
                ->where('storyHandoff.window_minutes', 120)
                ->has('storyHandoff.posters', 2)
                ->where('post.targets.0.handoff_required', true)
                ->where('post.targets.0.story_extras.link.url', 'https://example.com/offer')
                ->where('can.post_story_by_hand', true));
    }
}
