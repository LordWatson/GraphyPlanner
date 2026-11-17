<?php

namespace Tests\Feature\Stories;

use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Publishing\RetryFailedPostTargetsAction;
use App\Actions\Stories\IssueStoryHandoffLinkAction;
use App\Actions\Stories\ProcessStoryHandoffsAction;
use App\Actions\Stories\ShowStoryHandoffAction;
use App\Contracts\PushNotifier;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Decision D6, as built — one link per publish attempt. The token is derived from the handoff
 * (`StoryHandoff::plainToken()`), never stored and never rotated: the first email, the nudge, the
 * push and "Open handoff" all carry the same link, and only a new publish attempt (a retry, a
 * recall) gets a new one. Its lifetime runs from whichever is later, the Story's time or the
 * moment the attempt was opened.
 */
class StoryHandoffLinkTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
        Notification::fake();
        $this->withoutVite();

        $this->owner = $this->member(Role::Owner);
    }

    private function publishingStory(): Post
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $this->refusingAdapter();

        return $this->startPublishing($post);
    }

    private function tokenIn(string $url): string
    {
        return (string) last(explode('/', (string) parse_url($url, PHP_URL_PATH)));
    }

    private function assertGone(string $token): void
    {
        try {
            app(ShowStoryHandoffAction::class)->resolve($token);
            $this->fail('The link still opens.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
    }

    // --- One link per attempt -----------------------------------------------------------------

    public function test_the_hash_of_the_derived_token_is_stored_when_the_handoff_is_opened(): void
    {
        $handoff = $this->openHandoff($this->publishingStory());
        $token = $handoff->plainToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $handoff->token_hash);
        // The link is never the thing at rest: holding the stored hash opens nothing.
        $this->assertNotSame($token, $handoff->token_hash);
        $this->assertGone($handoff->token_hash);

        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($token)->id);
        $this->assertArrayNotHasKey('token_hash', $handoff->toArray());
    }

    public function test_issuing_twice_returns_the_identical_url_and_both_resolve(): void
    {
        $handoff = $this->openHandoff($this->publishingStory());
        $hash = $handoff->token_hash;
        $updatedAt = $handoff->updated_at?->toIso8601String();
        $issue = app(IssueStoryHandoffLinkAction::class);

        $this->travel(5)->minutes();
        $first = $issue($handoff);
        $second = $issue(StoryHandoff::query()->findOrFail($handoff->id));

        $this->assertSame($first, $second);
        $this->assertSame(route('story-handoff.show', ['token' => $handoff->plainToken()]), $first);

        $token = $this->tokenIn($first);
        $this->assertSame(64, strlen($token));
        $this->assertNotSame($token, $handoff->refresh()->token_hash);
        $this->assertSame($hash, $handoff->token_hash);
        $this->assertSame($updatedAt, $handoff->updated_at?->toIso8601String(), 'Issuing a link wrote to a row that was already in step.');

        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($this->tokenIn($first))->id);
        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($this->tokenIn($second))->id);
        $this->get($first)->assertOk();
    }

    public function test_two_handoffs_never_share_a_token(): void
    {
        $first = $this->openHandoff($this->publishingStory());
        $second = $this->openHandoff($this->publishingStory());

        $this->assertNotSame($first->plainToken(), $second->plainToken());
        $this->assertSame($first->id, app(ShowStoryHandoffAction::class)->resolve($first->plainToken())->id);
        $this->assertSame($second->id, app(ShowStoryHandoffAction::class)->resolve($second->plainToken())->id);
    }

    public function test_the_first_notifications_link_still_works_after_the_nudge_and_after_a_teammate_opens_it(): void
    {
        $push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $push);
        PushSubscription::factory()->create(['user_id' => $this->owner->id]);

        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $hash = $handoff->token_hash;

        $this->assertSame(1, app(ProcessStoryHandoffsAction::class)()['notified']);
        $firstUrl = $push->sent[0]['message']->url;
        $this->get($firstUrl)->assertOk();

        // The nudge goes out: it carries the same link, and the first one is not replaced.
        $this->travelTo('2026-11-20 10:15:00');
        $this->assertSame(1, app(ProcessStoryHandoffsAction::class)()['nudged']);
        $this->assertSame($firstUrl, $push->sent[1]['message']->url);
        $this->assertSame($hash, $handoff->refresh()->token_hash);
        $this->get($firstUrl)->assertOk();

        // A teammate opens the Story from inside Alima: same link again, nothing rewritten.
        $this->actingAs($this->member(Role::Designer))
            ->get(route('posts.story-handoff.open', ['post' => $post->id, 'target' => $handoff->post_target_id]))
            ->assertRedirect($firstUrl);
        $this->assertSame($hash, $handoff->refresh()->token_hash);

        auth()->logout();
        $token = $this->tokenIn($firstUrl);
        $this->get($firstUrl)->assertOk();
        $this->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect($firstUrl)
            ->assertSessionHasNoErrors();

        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->refresh()->outcome);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    // --- A new attempt is a new link ----------------------------------------------------------

    public function test_a_retry_opens_a_new_handoff_with_a_different_token_and_the_old_link_can_no_longer_confirm(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);
        $oldToken = $old->plainToken();

        $this->travelTo('2026-11-20 12:00:00');
        app(ProcessStoryHandoffsAction::class)();
        $this->assertSame(StoryHandoffOutcome::Missed, $old->refresh()->outcome);

        $this->travelTo('2026-11-20 12:30:00');
        app(RetryFailedPostTargetsAction::class)($post->refresh(), $this->owner);

        $new = StoryHandoff::query()->open()->sole();
        $newToken = $new->plainToken();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertNotSame($old->token_hash, $new->token_hash);
        $this->assertSame(hash('sha256', $newToken), $new->token_hash);
        $this->assertSame($new->id, app(ShowStoryHandoffAction::class)->resolve($newToken)->id);

        // The old link belongs to the attempt that was missed: it can do nothing to the new one.
        $page = route('story-handoff.show', ['token' => $oldToken]);
        $this->from($page)
            ->post(route('story-handoff.posted', ['token' => $oldToken]))
            ->assertSessionHasErrors(['handoff']);

        $this->assertSame(StoryHandoffOutcome::Missed, $old->refresh()->outcome);
        $this->assertTrue($new->refresh()->isOpen());
        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        $this->post(route('story-handoff.posted', ['token' => $newToken]))->assertSessionHasNoErrors();
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_a_recall_and_resubmit_gives_a_new_token_and_the_old_link_is_a_404(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);
        $oldToken = $old->plainToken();
        $this->get(route('story-handoff.show', ['token' => $oldToken]))->assertOk();

        app(ReschedulePostAction::class)($post, 1, $this->owner);

        $this->assertSame(StoryHandoffOutcome::Cancelled, $old->refresh()->outcome);
        $new = StoryHandoff::query()->open()->sole();
        $this->assertNotSame($oldToken, $new->plainToken());

        $this->assertGone($oldToken);
        $this->get(route('story-handoff.media', ['token' => $oldToken]))->assertNotFound();
        $this->post(route('story-handoff.posted', ['token' => $oldToken]))->assertNotFound();
        $this->get(route('story-handoff.show', ['token' => $new->plainToken()]))->assertOk();
        $this->assertTrue($new->refresh()->isOpen());
    }

    public function test_an_open_handoff_whose_channel_moved_to_a_new_attempt_is_a_404_before_the_sweep_closes_it(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $this->storyTarget($post)->update(['publish_attempt_id' => 'a-newer-attempt']);

        $this->assertTrue($handoff->refresh()->isOpen());
        $this->assertGone($handoff->plainToken());
    }

    // --- Healing a hash that is out of step ----------------------------------------------------

    public function test_a_tampered_token_hash_is_healed_the_next_time_a_link_is_issued(): void
    {
        $handoff = $this->openHandoff($this->publishingStory());
        $token = $handoff->plainToken();
        $good = $handoff->token_hash;
        StoryHandoff::query()->whereKey($handoff->id)->update(['token_hash' => hash('sha256', 'something-else')]);
        $this->assertGone($token);

        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        $url = app(IssueStoryHandoffLinkAction::class)($handoff->refresh());

        $this->assertSame(route('story-handoff.show', ['token' => $token]), $url);
        $this->assertSame($good, $handoff->refresh()->token_hash);
        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($token)->id);

        $logged = str_replace('\\/', '/', implode("\n", $lines));
        $this->assertStringContainsString('Story handoff link hash refreshed', $logged);
        $this->assertStringContainsString('"story_handoff_id":'.$handoff->id, $logged);
        $this->assertStringNotContainsString($token, $logged);
        $this->assertStringNotContainsString('/story/', $logged);
    }

    public function test_a_handoff_made_under_an_old_app_key_gets_a_working_link_when_one_is_next_issued(): void
    {
        $handoff = $this->openHandoff($this->publishingStory());
        $oldToken = $handoff->plainToken();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $newToken = $handoff->plainToken();
        $this->assertNotSame($oldToken, $newToken);

        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        $this->assertSame($newToken, $this->tokenIn($url));
        $this->assertSame(hash('sha256', $newToken), $handoff->refresh()->token_hash);
        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($newToken)->id);

        try {
            app(ShowStoryHandoffAction::class)->resolve($oldToken);
            $this->fail('A token derived under the old key still opens.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    // --- Lifetime -----------------------------------------------------------------------------

    public function test_a_story_retried_long_after_its_time_gets_a_link_that_opens_and_can_be_confirmed(): void
    {
        config(['publishing.story_handoff.token_ttl_hours' => 30]);

        // 31 hours is just past the old rule (the Story's time + 30 hours); 3 days is far past it.
        foreach (['2026-11-21 17:00:00', '2026-11-23 10:00:00'] as $retriedAt) {
            $this->travelTo('2026-11-20 10:00:00');
            $post = $this->publishingStory();
            $old = $this->openHandoff($post);

            $this->travelTo('2026-11-20 12:00:00');
            app(ProcessStoryHandoffsAction::class)();
            $this->assertSame(PostStatus::Failed, $post->refresh()->status, $retriedAt);

            $this->travelTo($retriedAt);
            app(RetryFailedPostTargetsAction::class)($post, $this->owner);

            $new = StoryHandoff::query()->where('post_id', $post->id)->open()->sole();
            $this->assertNotSame($old->id, $new->id);
            // Still due at the Story's own, long-past time; the link runs from when it was opened.
            $this->assertSame('2026-11-20 10:00:00', $new->due_at->format('Y-m-d H:i:s'), $retriedAt);
            $this->assertSame(
                now()->addHours(30)->format('Y-m-d H:i:s'),
                $new->linkExpiresAt()->format('Y-m-d H:i:s'),
                $retriedAt,
            );

            $token = $new->plainToken();
            $this->travel(10)->minutes();
            $this->get(route('story-handoff.show', ['token' => $token]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('story-handoff/show', false)->where('handoff.state', 'ready'));

            $this->post(route('story-handoff.posted', ['token' => $token]))
                ->assertRedirect(route('story-handoff.show', ['token' => $token]))
                ->assertSessionHasNoErrors();

            $this->assertSame(StoryHandoffOutcome::Posted, $new->refresh()->outcome, $retriedAt);
            $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status, $retriedAt);
            $this->assertSame(PostStatus::Published, $post->refresh()->status, $retriedAt);
        }
    }

    public function test_a_retried_storys_link_lasts_the_ttl_from_when_it_was_opened_and_no_longer(): void
    {
        config(['publishing.story_handoff.token_ttl_hours' => 30]);
        $post = $this->publishingStory();

        $this->travelTo('2026-11-20 12:00:00');
        app(ProcessStoryHandoffsAction::class)();

        $this->travelTo('2026-11-23 10:00:00');
        app(RetryFailedPostTargetsAction::class)($post->refresh(), $this->owner);
        $token = StoryHandoff::query()->open()->sole()->plainToken();

        $this->travelTo('2026-11-24 15:59:00');
        $this->get(route('story-handoff.show', ['token' => $token]))->assertOk();

        $this->travelTo('2026-11-24 16:00:01');
        $this->get(route('story-handoff.show', ['token' => $token]))->assertNotFound();
    }

    public function test_a_story_opened_before_its_time_still_counts_its_lifetime_from_the_due_time(): void
    {
        config(['publishing.story_handoff.token_ttl_hours' => 30]);
        $this->travelTo('2026-11-20 08:00:00');
        $handoff = $this->openHandoff($this->publishingStory());

        $this->assertSame('2026-11-20 10:00:00', $handoff->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-21 16:00:00', $handoff->linkExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function test_the_unused_auto_completion_constant_is_gone(): void
    {
        $this->assertFalse(defined(StoryHandoff::class.'::VIA_AUTO'));
        $this->assertSame(['link', 'staff'], [StoryHandoff::VIA_LINK, StoryHandoff::VIA_STAFF]);
    }
}
