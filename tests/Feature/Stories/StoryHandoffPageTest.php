<?php

namespace Tests\Feature\Stories;

use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\ExpireStoryHandoffAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-4.1 / S-6.4 — the tokenised Story handoff page: a token opens one Story's handoff and
 * nothing else, and the three writes behind it (shared, posted, problem).
 */
class StoryHandoffPageTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    /** Keys that must never reach the handoff page, at any depth. */
    private const DENIED_KEYS = [
        'master_caption', 'caption', 'captions', 'hashtags', 'retainer_amount', 'retainer', 'invoices', 'invoice',
        'billing', 'billing_email', 'comments', 'notes_internal', 'internal_notes', 'targets', 'channels',
        'activity_logs', 'token_hash', 'org_id', 'organization', 'post', 'posts', 'email',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
        Notification::fake();
        // The React page is built separately; these tests are about the data it is given.
        $this->withoutVite();
    }

    /**
     * A Story that is out for posting by hand, and the plain token of its link.
     *
     * @param  array<string, mixed>|null  $extras
     * @param  array<string, mixed>  $postAttributes
     * @return array{0: Post, 1: StoryHandoff, 2: string}
     */
    private function readyStory(?array $extras = null, array $postAttributes = []): array
    {
        $post = $this->startPublishing($this->storyPost(extras: $extras, postAttributes: $postAttributes));
        $handoff = $this->openHandoff($post);

        return [$post, $handoff, $this->issueToken($handoff)];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function handoffProp(TestResponse $response): array
    {
        return $response->inertiaProps()['handoff'];
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function keysDeep(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = [...$keys, ...self::keysDeep($value)];
            }
        }

        return $keys;
    }

    public function test_a_valid_token_renders_the_handoff_payload(): void
    {
        [$post, , $token] = $this->readyStory([
            'version' => 1,
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'mentions' => ['@partner'],
            'placement_note' => 'Link sticker bottom left.',
        ]);
        $this->storyTarget($post)->socialAccount()->update(['timezone' => 'America/New_York']);
        $asset = Asset::factory()->uploaded()->create(['client_id' => $this->client->id]);
        $post->assets()->attach($asset);

        $response = $this->get(route('story-handoff.show', ['token' => $token]));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('story-handoff/show', false)
            ->where('token', $token)
            ->where('handoff.state', 'ready')
            ->where('handoff.late', false)
            ->where('handoff.account.handle', '@acmecoffee')
            ->where('handoff.account.platform', 'instagram')
            ->where('handoff.account.platform_label', 'Instagram')
            ->where('handoff.client.name', 'Acme Coffee')
            ->where('handoff.due.date', '2026-11-20')
            ->where('handoff.due.time', '05:00')
            ->where('handoff.due.timezone', 'America/New_York')
            ->where('handoff.posted_by', 'team')
            ->where('handoff.link', 'https://example.com/offer')
            ->where('handoff.placement_note', 'Link sticker bottom left.')
            ->where('handoff.media.kind', 'image')
            ->where('handoff.media.url', route('story-handoff.media', ['token' => $token]))
            ->where('handoff.media.filename', 'alima-story.jpg')
            ->where('handoff.completed', null)
            ->where('handoff.can_confirm_late', false)
            ->where('can.openPost', false)
            ->where('postUrl', null)
            ->where('next', null));

        $this->assertSame([
            ['key' => 'link', 'label' => 'Link sticker', 'value' => 'https://example.com/offer'],
            ['key' => 'link_text', 'label' => 'Sticker text', 'value' => 'Shop now'],
            ['key' => 'mention', 'label' => 'Mention', 'value' => '@partner'],
        ], $this->handoffProp($response)['extras']);
    }

    public function test_a_video_story_is_described_as_a_video(): void
    {
        [$post, , $token] = $this->readyStory();
        $video = Asset::factory()->uploaded()->create([
            'client_id' => $this->client->id,
            'type' => 'video',
            'mime_type' => 'video/mp4',
        ]);
        $post->assets()->attach($video);

        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.media.kind', 'video')
                ->where('handoff.media.mime_type', 'video/mp4')
                ->where('handoff.media.filename', 'alima-story.mp4'));
    }

    public function test_opened_at_is_stamped_on_the_first_view_only(): void
    {
        [, $handoff, $token] = $this->readyStory();
        $this->assertNull($handoff->opened_at);

        $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();
        $this->assertSame('2026-11-20 10:00:00', $handoff->refresh()->opened_at?->format('Y-m-d H:i:s'));

        $this->travel(7)->minutes();
        $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $this->assertDatabaseHas('story_handoffs', ['id' => $handoff->id, 'opened_at' => '2026-11-20 10:00:00']);
    }

    public function test_a_wrong_token_is_a_404(): void
    {
        [, $handoff] = $this->readyStory();

        $this->get(route('story-handoff.show', ['token' => Str::random(64)]))->assertNotFound();
        // The stored hash is not a token either.
        $this->get(route('story-handoff.show', ['token' => $handoff->token_hash]))->assertNotFound();
        $this->assertNull($handoff->refresh()->opened_at);
    }

    public function test_an_expired_token_is_a_404(): void
    {
        config(['publishing.story_handoff.token_ttl_hours' => 30]);
        [, , $token] = $this->readyStory();

        // Due 10:00 on the 20th; the link opens for 30 hours after that.
        $this->travelTo('2026-11-21 15:59:00');
        $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $this->travelTo('2026-11-21 16:00:01');
        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
        $this->get(route('story-handoff.media', ['token' => $token]))->assertNotFound();
        $this->post(route('story-handoff.posted', ['token' => $token]))->assertNotFound();
    }

    public function test_a_cancelled_handoff_is_a_404(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $handoff->update(['outcome' => StoryHandoffOutcome::Cancelled, 'completed_at' => now()]);

        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
        $this->post(route('story-handoff.posted', ['token' => $token]))->assertNotFound();
        $this->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'It would not post.'])->assertNotFound();

        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
    }

    public function test_an_open_handoff_for_a_superseded_attempt_is_a_404(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        // A retry or a recall put the channel on a new attempt; the sweep has not closed this one yet.
        $this->storyTarget($post)->update(['publish_attempt_id' => (string) Str::uuid()]);

        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
        $this->post(route('story-handoff.shared', ['token' => $token]))->assertNotFound();

        $this->assertTrue($handoff->refresh()->isOpen());
        $this->assertNull($handoff->opened_at);
        $this->assertNull($handoff->shared_at);
    }

    public function test_an_archived_clients_link_is_a_404(): void
    {
        [, , $token] = $this->readyStory();
        $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $this->client->delete();

        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
    }

    public function test_a_token_only_ever_opens_its_own_clients_story(): void
    {
        [, , $tokenA] = $this->readyStory();

        $acme = $this->client;
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Bravo Bakery']);
        $postB = $this->storyPost();
        $this->storyTarget($postB)->socialAccount()->update(['handle' => '@bravobakery']);
        $postB = $this->startPublishing($postB);
        $tokenB = $this->issueToken($this->openHandoff($postB));
        $this->client = $acme;

        $a = $this->get(route('story-handoff.show', ['token' => $tokenA]))->assertOk();
        $a->assertInertia(fn ($page) => $page
            ->component('story-handoff/show', false)
            ->where('handoff.client.name', 'Acme Coffee')
            ->where('handoff.account.handle', '@acmecoffee')
            ->where('handoff.media', null));
        $a->assertDontSee('Bravo Bakery')->assertDontSee('@bravobakery')->assertDontSee($tokenB);

        $b = $this->get(route('story-handoff.show', ['token' => $tokenB]))->assertOk();
        $b->assertInertia(fn ($page) => $page
            ->component('story-handoff/show', false)
            ->where('handoff.client.name', 'Bravo Bakery')
            ->where('handoff.account.handle', '@bravobakery'));
        $b->assertDontSee('Acme Coffee')->assertDontSee('@acmecoffee')->assertDontSee($tokenA);
    }

    public function test_the_payload_has_no_caption_billing_comments_or_other_channels(): void
    {
        $author = $this->member(Role::Strategist);
        $post = $this->storyPost(postAttributes: ['master_caption' => 'TOP SECRET CAPTION']);
        $feed = $this->addFeedChannel($post);
        $feed->socialAccount()->update(['handle' => 'acme-on-linkedin']);
        $post->comments()->create(['user_id' => $author->id, 'body' => 'INTERNAL COMMENT TEXT']);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        $token = $this->issueToken($this->openHandoff($post));

        $response = $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $handoff = $this->handoffProp($response);

        $this->assertEqualsCanonicalizing([
            'state', 'late', 'early', 'due_at', 'due', 'client', 'account', 'posted_by', 'poster_name',
            'extras', 'link', 'placement_note', 'media', 'completed', 'can_confirm_late',
        ], array_keys($handoff));
        $this->assertSame(['name'], array_keys($handoff['client']));
        $this->assertEqualsCanonicalizing(['platform', 'platform_label', 'handle'], array_keys($handoff['account']));

        $this->assertSame([], array_values(array_intersect(self::DENIED_KEYS, self::keysDeep($handoff))));

        // Nothing about the post beyond the Story itself is anywhere in the page's props, shared
        // ones included.
        $props = (string) json_encode($response->inertiaProps());

        foreach (['TOP SECRET CAPTION', 'INTERNAL COMMENT TEXT', 'acme-on-linkedin'] as $secret) {
            $this->assertStringNotContainsString($secret, $props);
            $response->assertDontSee($secret);
        }
    }

    public function test_a_completed_handoff_still_opens_read_only(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $sam = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create(['name' => 'Sam Poster']);
        $this->travel(4)->minutes();
        app(CompleteStoryHandoffAction::class)($handoff, $sam);

        $this->travel(10)->minutes();

        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'posted')
                ->where('handoff.late', false)
                ->where('handoff.completed.by', 'Sam Poster')
                ->where('handoff.completed.at', $handoff->refresh()->completed_at?->toIso8601String())
                ->where('handoff.can_confirm_late', false));

        // Looking at a finished handoff is not "opening" or "sharing" it.
        $this->post(route('story-handoff.shared', ['token' => $token]))->assertNoContent();
        $handoff->refresh();
        $this->assertNull($handoff->opened_at);
        $this->assertNull($handoff->shared_at);
        $this->assertSame('2026-11-20 10:04:00', $handoff->completed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_a_missed_handoff_can_be_confirmed_late_while_its_channel_is_still_failed_for_that_reason(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $this->travelTo('2026-11-20 12:01:00');
        $this->assertSame(StoryHandoffOutcome::Missed, app(ExpireStoryHandoffAction::class)($handoff));
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'missed')
                ->where('handoff.can_confirm_late', true));

        // Once the channel has moved on (a retry put it back in the queue), it cannot.
        $story = $this->storyTarget($post);
        $story->update(['status' => PostTargetStatus::Pending, 'error' => null]);

        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'missed')
                ->where('handoff.can_confirm_late', false));
    }

    public function test_a_missed_story_can_be_confirmed_from_the_link_after_all(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $this->travelTo('2026-11-20 12:01:00');
        app(ExpireStoryHandoffAction::class)($handoff);

        $this->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->refresh()->outcome);
    }

    public function test_the_page_is_private_to_caches_crawlers_and_referrers(): void
    {
        [, , $token] = $this->readyStory();

        $response = $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_every_token_route_is_throttled(): void
    {
        foreach (['show', 'media', 'shared', 'posted', 'problem'] as $name) {
            $route = Route::getRoutes()->getByName('story-handoff.'.$name);

            $this->assertNotNull($route, "story-handoff.{$name} is not registered.");
            $this->assertContains('throttle:public-token', $route->gatherMiddleware(), "story-handoff.{$name} is not throttled.");
            $this->assertNotContains('auth', $route->gatherMiddleware(), "story-handoff.{$name} must open without signing in.");
        }
    }

    public function test_a_signed_in_member_of_the_posts_org_can_jump_to_the_post(): void
    {
        [$post, , $token] = $this->readyStory();

        $this->actingAs($this->member(Role::Designer))
            ->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('can.openPost', true)
                ->where('postUrl', route('posts.edit', $post->id)));
    }

    public function test_a_guest_gets_no_way_into_the_post(): void
    {
        [, , $token] = $this->readyStory();

        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('can.openPost', false)
                ->where('postUrl', null));
    }

    public function test_a_member_of_another_org_with_the_link_sees_the_page_but_not_the_post(): void
    {
        [, , $token] = $this->readyStory();
        $outsider = $this->member(Role::Owner, Organization::factory()->create());

        $this->actingAs($outsider)
            ->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'ready')
                ->where('handoff.client.name', 'Acme Coffee')
                ->where('can.openPost', false)
                ->where('postUrl', null)
                ->where('next', null));
    }

    public function test_a_signed_in_member_with_another_story_due_is_offered_the_next_one(): void
    {
        $me = $this->member(Role::Designer);
        [, , $token] = $this->readyStory(postAttributes: ['assignee_id' => $me->id]);
        $other = $this->startPublishing($this->storyPost(utc: '2026-11-20 09:30:00', postAttributes: ['assignee_id' => $me->id]));

        $this->travel(1)->minutes();

        $this->actingAs($me)
            ->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('next.url', route('posts.story-handoff.open', ['post' => $other->id, 'target' => $this->storyTarget($other)->id]))
                ->where('next.client_name', 'Acme Coffee'));
    }

    public function test_shared_stamps_shared_at_once_and_answers_204(): void
    {
        [$post, $handoff, $token] = $this->readyStory();

        $this->post(route('story-handoff.shared', ['token' => $token]))->assertNoContent();
        $this->assertSame('2026-11-20 10:00:00', $handoff->refresh()->shared_at?->format('Y-m-d H:i:s'));

        $this->travel(3)->minutes();
        $this->post(route('story-handoff.shared', ['token' => $token]))->assertNoContent();

        $handoff->refresh();
        $this->assertSame('2026-11-20 10:00:00', $handoff->shared_at?->format('Y-m-d H:i:s'));
        // Sharing never changes the channel.
        $this->assertTrue($handoff->isOpen());
        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_shared_with_a_wrong_token_is_a_404(): void
    {
        [, $handoff] = $this->readyStory();

        $this->post(route('story-handoff.shared', ['token' => Str::random(64)]))->assertNotFound();

        $this->assertNull($handoff->refresh()->shared_at);
    }

    public function test_posted_publishes_the_channel_and_records_the_signed_in_member(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $member = $this->member(Role::Strategist);

        $this->actingAs($member)
            ->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertNotNull($story->published_at);
        $this->assertDatabaseHas('story_handoffs', [
            'id' => $handoff->id,
            'outcome' => StoryHandoffOutcome::Posted->value,
            'completed_by_user_id' => $member->id,
            'completed_via' => StoryHandoff::VIA_LINK,
        ]);
        $this->assertNotNull($handoff->refresh()->completed_at);
    }

    public function test_posted_by_a_guest_records_nobody(): void
    {
        [$post, $handoff, $token] = $this->readyStory();

        $this->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]));

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertNull($handoff->completed_by_user_id);
        $this->assertSame(StoryHandoff::VIA_LINK, $handoff->completed_via);
    }

    public function test_a_second_posted_is_refused_with_a_friendly_error_and_changes_nothing(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $first = $this->member(Role::Strategist);
        $second = $this->member(Role::Designer);
        $page = route('story-handoff.show', ['token' => $token]);

        $this->actingAs($first)->post(route('story-handoff.posted', ['token' => $token]))->assertRedirect($page);
        $completedAt = $handoff->refresh()->completed_at?->toIso8601String();
        $activity = $post->activityLogs()->count();

        $this->travel(2)->minutes();

        $response = $this->actingAs($second)
            ->from($page)
            ->post(route('story-handoff.posted', ['token' => $token]));

        $response->assertRedirect($page)->assertSessionHasErrors(['handoff']);
        $this->assertStringContainsString('no longer active', (string) session('errors')->first('handoff'));

        $handoff->refresh();
        $this->assertSame($first->id, $handoff->completed_by_user_id);
        $this->assertSame($completedAt, $handoff->completed_at?->toIso8601String());
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame($activity, $post->activityLogs()->count());
        $this->assertSame(1, StoryHandoff::query()->withoutGlobalScopes()->count());
    }

    public function test_a_problem_needs_a_note(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $page = route('story-handoff.show', ['token' => $token]);

        $this->from($page)
            ->post(route('story-handoff.problem', ['token' => $token]), [])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['note']);

        $this->from($page)
            ->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'x'])
            ->assertSessionHasErrors(['note']);

        $this->from($page)
            ->post(route('story-handoff.problem', ['token' => $token]), ['note' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['note']);

        $this->assertTrue($handoff->refresh()->isOpen());
        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_a_problem_fails_the_channel_with_the_note(): void
    {
        [$post, $handoff, $token] = $this->readyStory();

        $this->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'Instagram would not take the video.'])
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $post->refresh();
        $story = $this->storyTarget($post);
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertStringContainsString('Instagram would not take the video.', (string) $story->error);
        $this->assertSame(PublishFailureReason::StoryProblemReported, PublishFailureReason::classify($story->error));

        $this->assertDatabaseHas('story_handoffs', [
            'id' => $handoff->id,
            'outcome' => StoryHandoffOutcome::Problem->value,
            'problem_note' => 'Instagram would not take the video.',
            'completed_by_user_id' => null,
        ]);

        // The page still opens, read-only, saying what happened.
        $this->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'problem')
                ->where('handoff.can_confirm_late', false));
    }

    public function test_a_problem_cannot_be_reported_twice(): void
    {
        [, $handoff, $token] = $this->readyStory();
        $page = route('story-handoff.show', ['token' => $token]);
        $this->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'First note.'])->assertRedirect($page);

        $this->from($page)
            ->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'Second note.'])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['handoff']);

        $this->assertSame('First note.', $handoff->refresh()->problem_note);
    }
}
