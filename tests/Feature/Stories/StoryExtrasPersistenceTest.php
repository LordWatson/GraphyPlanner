<?php

namespace Tests\Feature\Stories;

use App\Actions\Posts\DuplicatePostAction;
use App\Actions\Posts\RecyclePostAction;
use App\Actions\Posts\RestorePostRevisionAction;
use App\Actions\Stories\SyncTargetStoryExtrasAction;
use App\Enums\Platform;
use App\Enums\PostRecurrence;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffRecipient;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Steps S-1.1 and S-1.2 — a Story channel's extras are stored, and `handoff_required` is right
 * after every writer: the editor's save, duplicate, recycle and restoring a revision.
 */
class StoryExtrasPersistenceTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $owner;

    private SocialAccount $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStoryHandoffs();
        $this->owner = $this->member(Role::Owner);
        $this->instagram = $this->instagramAccount();
    }

    private function draft(): Post
    {
        return Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
    }

    /**
     * Save the post through the editor's route with one Instagram channel.
     *
     * @param  array<string, mixed>  $channel
     * @return TestResponse<Response>
     */
    private function save(Post $post, array $channel): TestResponse
    {
        return $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [[
                'social_account_id' => $this->instagram->id,
                'scheduled_local_date' => '2026-11-20',
                'scheduled_local_time' => '10:00',
                ...$channel,
            ]],
        ]);
    }

    private function channel(Post $post): PostTarget
    {
        return $post->targets()->with('socialAccount')->where('social_account_id', $this->instagram->id)->firstOrFail();
    }

    public function test_saving_a_new_story_channel_stores_extras_sent_as_one_json_string_and_flags_it(): void
    {
        $post = $this->draft();

        $response = $this->save($post, [
            'format' => 'story',
            'story_extras' => json_encode([
                'version' => 1,
                'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
                'mentions' => ['@acmecoffee', 'acmecoffee'],
                'hashtag' => '#summersale',
                'placement_note' => 'Bottom centre',
            ]),
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

        $target = $this->channel($post);
        $this->assertEquals([
            'version' => 1,
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'mentions' => ['acmecoffee'],
            'hashtag' => 'summersale',
            'placement_note' => 'Bottom centre',
        ], $target->story_extras);
        $this->assertTrue($target->handoff_required);
        $this->assertTrue($target->needsHandoff());
        $this->assertDatabaseHas('post_targets', ['id' => $target->id, 'format' => 'story', 'handoff_required' => true]);
    }

    public function test_updating_a_channel_stores_extras_sent_as_a_real_array(): void
    {
        $post = $this->draft();
        $existing = $this->addStoryChannel($post, extras: [], account: $this->instagram);
        $this->assertFalse($existing->handoff_required);

        $this->save($post, [
            'format' => 'story',
            'story_extras' => ['poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']]],
        ])->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

        $target = $this->channel($post);
        $this->assertSame($existing->id, $target->id, 'The channel is updated in place.');
        $this->assertEquals(['version' => 1, 'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']]], $target->story_extras);
        $this->assertTrue($target->handoff_required);

        // A later save replaces the extras with what was sent.
        $this->save($post, ['format' => 'story', 'story_extras' => ['location' => 'Leeds']])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals(['version' => 1, 'location' => 'Leeds'], $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_with_the_kill_switch_off_submitted_extras_are_not_stored_and_the_channel_is_not_flagged(): void
    {
        config(['publishing.story_handoff.enabled' => false]);
        $post = $this->draft();

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])
            ->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

        $target = $this->channel($post);
        $this->assertSame('story', $target->format);
        $this->assertNull($target->story_extras);
        $this->assertFalse($target->handoff_required);

        // Sent as an array, the same.
        $this->save($post, ['format' => 'story', 'story_extras' => $this->linkExtras()])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertNull($target->story_extras);
        $this->assertFalse($target->handoff_required);
        $this->assertDatabaseMissing('post_targets', ['post_id' => $post->id, 'handoff_required' => true]);
    }

    public function test_with_the_kill_switch_off_a_save_keeps_stored_extras_but_writes_the_flag_false(): void
    {
        $post = $this->draft();
        $this->addStoryChannel($post, account: $this->instagram);
        $this->assertTrue($this->channel($post)->handoff_required);

        config(['publishing.story_handoff.enabled' => false]);

        $this->save($post, ['format' => 'story', 'story_extras' => ['hashtag' => 'replaced']])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals($this->linkExtras(), $target->story_extras, 'What is already stored is left alone.');
        $this->assertFalse($target->handoff_required);
    }

    public function test_changing_the_format_away_from_story_keeps_the_extras_but_clears_the_flag_and_switching_back_restores_it(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();
        $this->assertTrue($this->channel($post)->handoff_required);

        // The format alone changes; the extras are not part of this save.
        $this->save($post, ['format' => 'feed'])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertSame('feed', $target->format);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertFalse($target->handoff_required);
        $this->assertFalse($target->needsHandoff());

        // Back to Auto (no explicit format) with the extras re-sent: still not a Story.
        $this->save($post, ['format' => '', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertNull($target->format);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertFalse($target->handoff_required);

        $this->save($post, ['format' => 'story'])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_extras_on_an_instagram_channel_that_is_not_a_story_are_stored_but_not_flagged(): void
    {
        $post = $this->draft();

        $this->save($post, ['format' => 'feed', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertFalse($target->handoff_required);
    }

    public function test_removing_every_extra_clears_the_flag(): void
    {
        foreach (['empty string' => '', 'null' => null] as $case => $cleared) {
            $post = $this->draft();
            $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();
            $this->assertTrue($this->channel($post)->handoff_required);

            $this->save($post, ['format' => 'story', 'story_extras' => $cleared])->assertSessionHasNoErrors();

            $target = $this->channel($post);
            $this->assertNull($target->story_extras, "Extras should be cleared by: {$case}");
            $this->assertFalse($target->handoff_required, "Flag should be cleared by: {$case}");
            $this->assertSame('story', $target->format);
        }
    }

    /**
     * Plan §4.1: "Null or `{}` = none". An empty object is a submitted value, so it must clear the
     * stored extras like null does, not be mistaken for "extras not sent".
     */
    public function test_an_empty_extras_object_clears_the_extras_and_the_flag(): void
    {
        foreach (['empty json object' => '{}', 'empty array' => []] as $case => $cleared) {
            $post = $this->draft();
            $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();

            $this->save($post, ['format' => 'story', 'story_extras' => $cleared])->assertSessionHasNoErrors();

            $target = $this->channel($post);
            $this->assertNull($target->story_extras, "Extras should be cleared by: {$case}");
            $this->assertFalse($target->handoff_required, "Flag should be cleared by: {$case}");
        }
    }

    /**
     * `StoryExtras::fromArray()`: "an extra is added when its key is present". A link or poll sent
     * as an empty object is added-but-blank, and must not vanish on the way through the request.
     */
    public function test_a_link_or_poll_sent_as_an_empty_object_is_kept_as_added(): void
    {
        $post = $this->draft();

        $this->save($post, ['format' => 'story', 'story_extras' => '{"link":{},"poll":{}}'])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals([
            'version' => 1,
            'link' => ['url' => '', 'text' => null],
            'poll' => ['question' => '', 'options' => []],
        ], $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_a_save_that_does_not_mention_extras_leaves_them_alone(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();

        $this->save($post, ['format' => 'story', 'caption_override' => 'New caption'])->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertSame('New caption', $target->caption_override);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_a_placement_note_alone_does_not_flag_the_channel(): void
    {
        $post = $this->draft();

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode(['placement_note' => 'Top left'])])
            ->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals(['version' => 1, 'placement_note' => 'Top left'], $target->story_extras);
        $this->assertFalse($target->handoff_required);

        // Dropping the last real extra but keeping the note clears the flag too.
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode([...$this->linkExtras(), 'placement_note' => 'Top left'])])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->channel($post)->handoff_required);

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode(['placement_note' => 'Top left'])])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->channel($post)->handoff_required);
    }

    public function test_an_extra_that_was_added_but_not_filled_in_is_stored_and_flags_the_channel(): void
    {
        $post = $this->draft();

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode(['link' => ['url' => '', 'text' => null], 'mentions' => []])])
            ->assertSessionHasNoErrors();

        $target = $this->channel($post);
        $this->assertEquals(['version' => 1, 'link' => ['url' => '', 'text' => null], 'mentions' => []], $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_the_handoff_recipient_is_stored_and_can_be_cleared(): void
    {
        $post = $this->draft();

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras()), 'handoff_recipient' => 'client'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StoryHandoffRecipient::Client, $this->channel($post)->handoff_recipient);
        $this->assertDatabaseHas('post_targets', ['post_id' => $post->id, 'handoff_recipient' => 'client']);

        $this->save($post, ['format' => 'story', 'handoff_recipient' => 'team'])->assertSessionHasNoErrors();
        $this->assertSame(StoryHandoffRecipient::Team, $this->channel($post)->handoff_recipient);

        // Not sent: left as it is.
        $this->save($post, ['format' => 'story'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('post_targets', ['post_id' => $post->id, 'handoff_recipient' => 'team']);

        // Sent empty: back to the client's default.
        $this->save($post, ['format' => 'story', 'handoff_recipient' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->channel($post)->handoff_recipient);
    }

    public function test_an_invalid_handoff_recipient_is_rejected(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story', 'handoff_recipient' => 'client'])->assertSessionHasNoErrors();

        $this->save($post, ['format' => 'story', 'handoff_recipient' => 'everyone'])
            ->assertSessionHasErrors(['targets.0.handoff_recipient']);

        $this->assertSame(StoryHandoffRecipient::Client, $this->channel($post)->handoff_recipient);
    }

    public function test_duplicating_a_post_carries_extras_and_recipient_and_derives_the_copys_flag(): void
    {
        $source = $this->storyPost(PostStatus::Scheduled);
        $story = $this->storyTarget($source);
        $story->update(['handoff_recipient' => StoryHandoffRecipient::Client]);
        $feed = $this->addFeedChannel($source);

        $copy = app(DuplicatePostAction::class)($source->refresh(), $this->owner);

        $this->assertSame(PostStatus::Draft, $copy->status);
        $copied = $copy->targets()->where('social_account_id', $story->social_account_id)->firstOrFail();
        $this->assertNotSame($story->id, $copied->id);
        $this->assertSame('story', $copied->format);
        $this->assertEquals($this->linkExtras(), $copied->story_extras);
        $this->assertSame(StoryHandoffRecipient::Client, $copied->handoff_recipient);
        $this->assertTrue($copied->handoff_required);
        $this->assertNull($copied->scheduled_at_utc);

        $copiedFeed = $copy->targets()->where('social_account_id', $feed->social_account_id)->firstOrFail();
        $this->assertNull($copiedFeed->story_extras);
        $this->assertFalse($copiedFeed->handoff_required);
    }

    public function test_a_duplicates_flag_is_derived_not_copied_from_the_source(): void
    {
        // A source whose stored flag is stale in both directions.
        $source = $this->storyPost(PostStatus::Draft);
        PostTarget::query()->whereKey($this->storyTarget($source)->id)->update(['handoff_required' => false]);
        $plain = $this->addFeedChannel($source);
        PostTarget::query()->whereKey($plain->id)->update(['handoff_required' => true]);

        $copy = app(DuplicatePostAction::class)($source->refresh(), $this->owner);

        $this->assertTrue($copy->targets()->where('format', 'story')->firstOrFail()->handoff_required);
        $this->assertFalse($copy->targets()->where('social_account_id', $plain->social_account_id)->firstOrFail()->handoff_required);

        // With the lane switched off the extras still come along, unflagged.
        config(['publishing.story_handoff.enabled' => false]);
        $off = app(DuplicatePostAction::class)($source->refresh(), $this->owner);
        $offStory = $off->targets()->where('format', 'story')->firstOrFail();

        $this->assertEquals($this->linkExtras(), $offStory->story_extras);
        $this->assertFalse($offStory->handoff_required);
    }

    public function test_recycling_a_post_carries_extras_and_recipient_and_derives_the_copys_flag(): void
    {
        $source = $this->storyPost(PostStatus::Published, postAttributes: ['is_recyclable' => true]);
        $story = $this->storyTarget($source);
        $story->update(['handoff_recipient' => StoryHandoffRecipient::Client]);
        // Stale on the source: the copy must not inherit it.
        PostTarget::query()->whereKey($story->id)->update(['handoff_required' => false]);

        $copy = app(RecyclePostAction::class)($source->refresh(), $this->owner);

        $this->assertSame(PostStatus::Idea, $copy->status);
        $this->assertSame($source->id, $copy->recycled_from_post_id);

        $copied = $copy->targets()->firstOrFail();
        $this->assertSame('story', $copied->format);
        $this->assertEquals($this->linkExtras(), $copied->story_extras);
        $this->assertSame(StoryHandoffRecipient::Client, $copied->handoff_recipient);
        $this->assertTrue($copied->handoff_required);
        $this->assertNull($copied->scheduled_local_date, 'A manual recycle drops the schedule.');
    }

    public function test_the_next_occurrence_of_a_recurring_post_carries_extras_and_recipient(): void
    {
        $source = $this->storyPost(PostStatus::Published, '2026-11-20 10:00:00');
        $story = $this->storyTarget($source);
        $story->update(['handoff_recipient' => StoryHandoffRecipient::Team]);
        $feed = $this->addFeedChannel($source, '2026-11-20 10:00:00');

        $copy = app(RecyclePostAction::class)($source->refresh(), null, PostRecurrence::Weekly);

        $this->assertSame(PostStatus::Draft, $copy->status);
        $this->assertSame(PostRecurrence::Weekly, $copy->recurrence);

        $copied = $copy->targets()->where('social_account_id', $story->social_account_id)->firstOrFail();
        $this->assertEquals($this->linkExtras(), $copied->story_extras);
        $this->assertSame(StoryHandoffRecipient::Team, $copied->handoff_recipient);
        $this->assertTrue($copied->handoff_required);
        $this->assertSame('2026-11-27', $copied->scheduled_local_date?->toDateString());
        $this->assertSame('2026-11-27 10:00:00', $copied->scheduled_at_utc?->toDateTimeString());

        $this->assertFalse($copy->targets()->where('social_account_id', $feed->social_account_id)->firstOrFail()->handoff_required);
    }

    public function test_a_revision_is_recorded_when_only_the_extras_change(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story'])->assertSessionHasNoErrors();
        $this->assertSame(1, $post->revisions()->count());
        $this->assertArrayNotHasKey('story_extras', $post->revisions()->latest('id')->firstOrFail()->snapshot['targets'][0]);

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();

        $this->assertSame(2, $post->revisions()->count());
        $latest = $post->revisions()->latest('id')->firstOrFail();
        $this->assertEquals($this->linkExtras(), $latest->snapshot['targets'][0]['story_extras']);
        $this->assertSame($this->owner->id, $latest->created_by);

        // The same extras again is not a change.
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();
        $this->assertSame(2, $post->revisions()->count());

        // One field inside the extras is.
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras(text: 'Book now'))])->assertSessionHasNoErrors();
        $this->assertSame(3, $post->revisions()->count());
    }

    public function test_restoring_a_revision_brings_back_its_extras(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();
        $withLink = $post->revisions()->latest('id')->firstOrFail();

        $poll = ['version' => 1, 'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']]];
        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($poll)])->assertSessionHasNoErrors();
        $this->assertEquals($poll, $this->channel($post)->story_extras);

        app(RestorePostRevisionAction::class)($post->refresh(), $withLink, $this->owner);

        $target = $this->channel($post);
        $this->assertEquals($this->linkExtras(), $target->story_extras);
        $this->assertTrue($target->handoff_required);
    }

    public function test_restoring_a_revision_from_before_the_extras_removes_them_and_clears_the_flag(): void
    {
        $post = $this->draft();
        $this->save($post, ['format' => 'story'])->assertSessionHasNoErrors();
        $plain = $post->revisions()->latest('id')->firstOrFail();

        $this->save($post, ['format' => 'story', 'story_extras' => json_encode($this->linkExtras())])->assertSessionHasNoErrors();
        $this->assertTrue($this->channel($post)->handoff_required);

        app(RestorePostRevisionAction::class)($post->refresh(), $plain, $this->owner);

        $target = $this->channel($post);
        $this->assertNull($target->story_extras);
        $this->assertFalse($target->handoff_required);
        $this->assertSame('story', $target->format);
    }

    /**
     * Decision D3: `handoff_required` is a stored copy of `needsHandoff()`. Walk every combination
     * of lane on/off, platform, format and extras, and check the copy never drifts — and that it
     * is only ever true for an Instagram Story with extras while the lane is on.
     */
    public function test_the_stored_flag_always_equals_needs_handoff(): void
    {
        $sync = app(SyncTargetStoryExtrasAction::class);
        $checked = 0;

        foreach ([true, false] as $enabled) {
            foreach ([Platform::Instagram, Platform::Facebook, Platform::LinkedIn] as $platform) {
                foreach ([true, false] as $story) {
                    foreach (['none', 'link', 'note only', 'unfinished'] as $extras) {
                        config(['publishing.story_handoff.enabled' => $enabled]);

                        $factory = $story ? PostTarget::factory()->story() : PostTarget::factory();
                        $factory = match ($extras) {
                            'link' => $factory->withStoryExtras(),
                            'note only' => $factory->withStoryExtras(['version' => 1, 'placement_note' => 'Top left']),
                            'unfinished' => $factory->withStoryExtras(['version' => 1, 'link' => ['url' => '', 'text' => null]]),
                            default => $factory,
                        };

                        $created = $factory->create([
                            'social_account_id' => SocialAccount::factory()->state(['platform' => $platform]),
                        ]);
                        $sync->refresh($created);

                        $target = PostTarget::query()->with('socialAccount')->findOrFail($created->id);
                        $case = sprintf('lane %s, %s, %s, extras: %s', $enabled ? 'on' : 'off', $platform->value, $story ? 'story' : 'not a story', $extras);
                        $expected = $enabled && $platform === Platform::Instagram && $story && in_array($extras, ['link', 'unfinished'], true);

                        $this->assertSame($target->needsHandoff(), $target->handoff_required, "Stored flag drifted for: {$case}");
                        $this->assertSame($expected, $target->handoff_required, "Wrong lane for: {$case}");
                        $checked++;
                    }
                }
            }
        }

        $this->assertSame(48, $checked);
    }

    public function test_the_flag_follows_the_channel_through_the_one_writer(): void
    {
        $sync = app(SyncTargetStoryExtrasAction::class);
        $created = PostTarget::factory()->story()->withStoryExtras()->create();
        $target = PostTarget::query()->with('socialAccount')->findOrFail($created->id);
        $this->assertTrue($target->handoff_required);

        // Format away and back.
        $target->format = 'feed';
        $target->save();
        $this->assertFalse($sync->refresh($target)->handoff_required);
        $this->assertNotNull($target->refresh()->story_extras);

        $target->format = 'story';
        $target->save();
        $this->assertTrue($sync->refresh($target)->handoff_required);

        // Extras cleared and set again.
        $this->assertFalse($sync($target, null)->handoff_required);
        $this->assertNull($target->refresh()->story_extras);
        $this->assertTrue($sync($target, ['hashtag' => '#sale'])->handoff_required);
        $this->assertEquals(['version' => 1, 'hashtag' => 'sale'], $target->refresh()->story_extras);

        // Lane switched off: nothing new is stored and the flag is written false.
        config(['publishing.story_handoff.enabled' => false]);
        $this->assertFalse($sync($target, ['location' => 'Leeds'])->handoff_required);
        $this->assertEquals(['version' => 1, 'hashtag' => 'sale'], $target->refresh()->story_extras);
        $this->assertDatabaseHas('post_targets', ['id' => $target->id, 'handoff_required' => false]);
    }
}
