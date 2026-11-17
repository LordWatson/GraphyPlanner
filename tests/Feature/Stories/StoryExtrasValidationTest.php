<?php

namespace Tests\Feature\Stories;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Stories\SyncTargetStoryExtrasAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffRecipient;
use App\Jobs\PublishPostJob;
use App\Models\Asset;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-1.3 — bad extras never reach a schedule: the request bounds their shape, the blocking
 * `story_extras` checklist item holds back unfinished ones, and the soft `story_handoff` item
 * warns when nobody obvious will post the Story.
 */
class StoryExtrasValidationTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $owner;

    private SocialAccount $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-13 09:00:00');
        $this->setUpStoryHandoffs();
        $this->owner = $this->member(Role::Owner);
        $this->instagram = $this->instagramAccount();
    }

    private function draft(): Post
    {
        return Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
    }

    /**
     * Save the post through the editor's route with one channel.
     *
     * @param  array<string, mixed>|string|null  $extras  an array is sent the way the editor sends it: one JSON string
     * @return TestResponse<Response>
     */
    private function saveExtras(Post $post, array|string|null $extras, ?SocialAccount $account = null, ?string $format = 'story', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->put(route('posts.update', $post), [
            'targets' => [[
                'social_account_id' => ($account ?? $this->instagram)->id,
                'scheduled_local_date' => '2026-11-20',
                'scheduled_local_time' => '10:00',
                'format' => $format,
                'story_extras' => is_array($extras) ? json_encode($extras) : $extras,
            ]],
        ]);
    }

    private function account(Platform $platform): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
            'timezone' => 'UTC',
        ]);
    }

    /**
     * @return array{key: string, label: string, passed: bool, soft?: bool, detail?: string}|null
     */
    private function checklistItem(Post $post, string $key): ?array
    {
        $checklist = (new EvaluatePostChecklistAction)(Post::query()->findOrFail($post->id));

        return collect($checklist['items'])->firstWhere('key', $key);
    }

    // ---------------------------------------------------------------------------------------------
    // Request rules (§4.2)
    // ---------------------------------------------------------------------------------------------

    public function test_each_shape_rule_rejects_the_save(): void
    {
        $cases = [
            'targets.0.story_extras.link.url' => ['link' => ['url' => 'http://example.com/offer', 'text' => 'Shop now']],
            'targets.0.story_extras.link.text' => ['link' => ['url' => 'https://example.com/offer', 'text' => str_repeat('a', 31)]],
            'targets.0.story_extras.mentions' => ['mentions' => ['one', 'two', 'three', 'four', 'five', 'six']],
            'targets.0.story_extras.mentions.1' => ['mentions' => ['acmecoffee', 'bad handle!']],
            'targets.0.story_extras.hashtag' => ['hashtag' => str_repeat('a', 101)],
            'targets.0.story_extras.location' => ['location' => str_repeat('a', 81)],
            'targets.0.story_extras.poll.question' => ['poll' => ['question' => str_repeat('a', 81), 'options' => ['Blue', 'Green']]],
            'targets.0.story_extras.poll.options' => ['poll' => ['question' => 'Which one?', 'options' => ['One', 'Two', 'Three', 'Four', 'Five']]],
            'targets.0.story_extras.poll.options.1' => ['poll' => ['question' => 'Which one?', 'options' => ['Blue', str_repeat('a', 26)]]],
            'targets.0.story_extras.placement_note' => ['placement_note' => str_repeat('a', 281)],
            'targets.0.story_extras.if_missed' => ['link' => ['url' => 'https://example.com/offer'], 'if_missed' => 'later'],
        ];

        foreach ($cases as $error => $extras) {
            $post = $this->draft();

            $this->saveExtras($post, $extras)->assertSessionHasErrors([$error]);

            $this->assertSame(0, $post->targets()->count(), "Nothing should be saved when {$error} fails.");
        }
    }

    public function test_a_link_that_is_not_https_is_rejected_in_every_form(): void
    {
        foreach (['http://example.com', 'example.com/offer', 'ftp://example.com/file', 'https://'.str_repeat('a', 2050).'.com'] as $url) {
            $this->saveExtras($this->draft(), ['link' => ['url' => $url]])
                ->assertSessionHasErrors(['targets.0.story_extras.link.url']);
        }

        // Sent as a real array, the same rule applies.
        $post = $this->draft();
        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [[
                'social_account_id' => $this->instagram->id,
                'format' => 'story',
                'story_extras' => ['link' => ['url' => 'http://example.com']],
            ]],
        ])->assertSessionHasErrors(['targets.0.story_extras.link.url']);
    }

    public function test_a_mention_with_illegal_characters_or_too_long_is_rejected(): void
    {
        foreach (['bad handle', 'acme!', 'acme-coffee', '@@acme', str_repeat('a', 31)] as $handle) {
            $this->saveExtras($this->draft(), ['mentions' => [$handle]])
                ->assertSessionHasErrors(['targets.0.story_extras.mentions.0']);
        }
    }

    public function test_extras_that_are_not_an_object_are_rejected(): void
    {
        $post = $this->draft();

        $this->saveExtras($post, 'not json')->assertSessionHasErrors(['targets.0.story_extras']);
        $this->saveExtras($post, '"a string"')->assertSessionHasErrors(['targets.0.story_extras']);
    }

    public function test_extras_exactly_at_the_limits_are_accepted(): void
    {
        $post = $this->draft();
        $extras = [
            'link' => ['url' => 'https://example.com/offer', 'text' => str_repeat('a', 30)],
            'mentions' => ['@one', 'two', 'three.3', 'four_4', str_repeat('a', 30)],
            'hashtag' => '#summersale',
            'location' => str_repeat('a', 80),
            'poll' => ['question' => str_repeat('a', 80), 'options' => ['One', 'Two', 'Three', str_repeat('a', 25)]],
            'placement_note' => str_repeat('a', 280),
            'if_missed' => 'publish_plain',
        ];

        $this->saveExtras($post, $extras)->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

        $target = $post->targets()->firstOrFail();
        $this->assertTrue($target->handoff_required);
        $this->assertSame(['one', 'two', 'three.3', 'four_4', str_repeat('a', 30)], $target->story_extras['mentions']);
        $this->assertSame('publish_plain', $target->story_extras['if_missed']);
        $this->assertSame([], $target->storyExtras()->problems());
    }

    public function test_extras_on_a_platform_that_is_not_on_the_lane_are_rejected(): void
    {
        $facebook = $this->account(Platform::Facebook);
        $linkedIn = $this->account(Platform::LinkedIn);

        // A Facebook Story: Facebook has the format, but the lane is Instagram only.
        $post = $this->draft();
        $this->saveExtras($post, $this->linkExtras(), $facebook)->assertSessionHasErrors(['targets.0.story_extras']);
        $this->assertSame(0, $post->targets()->count());

        // LinkedIn has no Story format at all.
        $this->saveExtras($post, $this->linkExtras(), $linkedIn, format: null)->assertSessionHasErrors(['targets.0.story_extras']);
        $this->assertDatabaseMissing('post_targets', ['post_id' => $post->id]);

        // Sent as a real array, the same.
        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [['social_account_id' => $facebook->id, 'format' => 'story', 'story_extras' => $this->linkExtras()]],
        ])->assertSessionHasErrors(['targets.0.story_extras']);
    }

    public function test_the_platform_check_only_looks_at_the_channel_that_has_extras(): void
    {
        $facebook = $this->account(Platform::Facebook);
        $post = $this->draft();

        $response = $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [
                ['social_account_id' => $this->instagram->id, 'format' => 'story', 'story_extras' => json_encode($this->linkExtras())],
                ['social_account_id' => $facebook->id, 'format' => 'story', 'story_extras' => json_encode(['hashtag' => 'sale'])],
            ],
        ]);

        $response->assertSessionHasErrors(['targets.1.story_extras']);
        $response->assertSessionDoesntHaveErrors(['targets.0.story_extras']);
    }

    public function test_a_placement_note_alone_is_not_refused_on_another_platform(): void
    {
        $facebook = $this->account(Platform::Facebook);
        $post = $this->draft();

        $this->saveExtras($post, ['placement_note' => 'Top left'], $facebook)->assertSessionHasNoErrors();

        $this->assertFalse($post->targets()->firstOrFail()->handoff_required);
    }

    public function test_with_the_kill_switch_off_extras_on_another_platform_are_silently_ignored(): void
    {
        config(['publishing.story_handoff.enabled' => false]);

        foreach ([[Platform::Facebook, 'story'], [Platform::LinkedIn, null]] as [$platform, $format]) {
            $account = $this->account($platform);
            $post = $this->draft();

            $this->saveExtras($post, $this->linkExtras(), $account, $format)
                ->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

            $target = $post->targets()->firstOrFail();
            $this->assertNull($target->story_extras);
            $this->assertFalse($target->handoff_required);
        }
    }

    public function test_the_shape_rules_still_apply_with_the_kill_switch_off(): void
    {
        config(['publishing.story_handoff.enabled' => false]);

        $this->saveExtras($this->draft(), ['link' => ['url' => 'http://example.com']])
            ->assertSessionHasErrors(['targets.0.story_extras.link.url']);
    }

    public function test_a_half_finished_extras_object_is_accepted_because_a_draft_may_be_unfinished(): void
    {
        $post = $this->draft();

        $this->saveExtras($post, [
            'link' => ['url' => '', 'text' => ''],
            'mentions' => [],
            'hashtag' => '',
            'location' => '',
            'poll' => ['question' => '', 'options' => ['Blue']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

        $target = $post->targets()->firstOrFail();
        $this->assertTrue($target->handoff_required);
        $this->assertEquals([
            'version' => 1,
            'link' => ['url' => '', 'text' => null],
            'mentions' => [],
            'hashtag' => '',
            'location' => '',
            'poll' => ['question' => '', 'options' => ['Blue']],
        ], $target->story_extras);

        // Saved, but the checklist the save refreshed says it cannot be scheduled yet.
        $this->assertFalse($post->refresh()->checklist_snapshot['passed'] ?? true);
        $this->assertFalse($this->checklistItem($post, 'story_extras')['passed'] ?? true);
    }

    // ---------------------------------------------------------------------------------------------
    // Who may submit extras
    // ---------------------------------------------------------------------------------------------

    /**
     * A client reviewer cannot edit posts at all. They never reach the editor's save: the
     * `RedirectClientReviewersToPortal` middleware sends them to their portal first, and behind it
     * `PostPolicy::update` (what `UpdatePostRequest::authorize()` asks) refuses them too.
     */
    public function test_a_client_reviewer_cannot_submit_extras(): void
    {
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $post = $this->draft();

        $this->saveExtras($post, $this->linkExtras(), as: $reviewer)->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, $post->targets()->count());
        $this->assertFalse($reviewer->can('update', $post));

        // Not even on a post that is waiting for them.
        $waiting = $this->storyPost(PostStatus::WaitingClient, extras: []);
        $this->saveExtras($waiting, $this->linkExtras(), $this->storyTarget($waiting)->socialAccount()->firstOrFail(), as: $reviewer)
            ->assertRedirect(route('portal.dashboard'));

        $this->assertFalse($reviewer->can('update', $waiting));
        $this->assertNull($this->storyTarget($waiting)->story_extras);
        $this->assertFalse($this->storyTarget($waiting)->handoff_required);
    }

    public function test_a_viewer_cannot_submit_extras(): void
    {
        $post = $this->draft();

        $this->saveExtras($post, $this->linkExtras(), as: $this->member(Role::Viewer))->assertForbidden();

        $this->assertSame(0, $post->targets()->count());
    }

    public function test_a_member_of_another_organization_cannot_submit_extras(): void
    {
        $stranger = $this->member(Role::Owner, Organization::factory()->create());
        $post = $this->draft();

        $status = $this->saveExtras($post, $this->linkExtras(), as: $stranger)->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame(0, $post->targets()->count());
    }

    public function test_strategists_and_designers_can_submit_extras(): void
    {
        foreach ([Role::Strategist, Role::Designer] as $role) {
            $post = $this->draft();

            $this->saveExtras($post, $this->linkExtras(), as: $this->member($role))->assertSessionHasNoErrors()->assertRedirect(route('posts.edit', $post));

            $this->assertTrue($post->targets()->firstOrFail()->handoff_required);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Checklist: the blocking `story_extras` item
    // ---------------------------------------------------------------------------------------------

    public function test_the_story_extras_item_blocks_unfinished_extras(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: [
            'link' => ['url' => ''],
            'poll' => ['question' => 'Which one?', 'options' => ['Blue']],
            'mentions' => [],
        ]);

        $item = $this->checklistItem($post, 'story_extras');

        $this->assertNotNull($item);
        $this->assertSame('Story extras are complete', $item['label']);
        $this->assertFalse($item['passed']);
        $this->assertArrayNotHasKey('soft', $item, 'Unfinished extras block scheduling; they are not a warning.');
        $this->assertStringContainsString('Instagram (@acmecoffee): the link sticker needs a link.', $item['detail'] ?? '');
        $this->assertStringContainsString('Instagram (@acmecoffee): the mention needs a handle.', $item['detail'] ?? '');
        $this->assertStringContainsString('Instagram (@acmecoffee): the poll needs at least two options.', $item['detail'] ?? '');

        $this->assertFalse((new EvaluatePostChecklistAction)(Post::query()->findOrFail($post->id))['passed']);
    }

    public function test_the_story_extras_item_blocks_a_link_that_is_not_https(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: ['link' => ['url' => 'http://example.com/offer']]);

        $item = $this->checklistItem($post, 'story_extras');

        $this->assertFalse($item['passed']);
        $this->assertStringContainsString('the link must be a full address starting with https://.', $item['detail'] ?? '');
    }

    public function test_the_story_extras_item_passes_when_the_extras_are_complete(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: [
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'mentions' => ['acmecoffee'],
            'hashtag' => 'summersale',
            'location' => 'Leeds',
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']],
        ]);

        $item = $this->checklistItem($post, 'story_extras');

        $this->assertNotNull($item);
        $this->assertTrue($item['passed']);
        $this->assertArrayNotHasKey('detail', $item);
    }

    public function test_the_story_items_are_absent_for_a_post_with_no_assisted_story(): void
    {
        $plainStory = $this->storyPost(PostStatus::Draft, extras: []);
        $noteOnly = $this->storyPost(PostStatus::Draft, extras: ['placement_note' => 'Top left']);
        $feedOnly = $this->draft();
        $this->addFeedChannel($feedOnly);
        $noChannels = $this->draft();

        // Extras on a channel that is not a Story do not put it on the assisted lane either.
        $notAStory = $this->storyPost(PostStatus::Draft);
        $this->storyTarget($notAStory)->update(['format' => 'feed']);

        foreach ([$plainStory, $noteOnly, $feedOnly, $noChannels, $notAStory] as $post) {
            $this->assertNull($this->checklistItem($post, 'story_extras'));
            $this->assertNull($this->checklistItem($post, 'story_handoff'));
        }

        // And with the lane switched off, a Story that has extras stored.
        $assisted = $this->storyPost(PostStatus::Draft);
        $this->assertNotNull($this->checklistItem($assisted, 'story_extras'));
        $this->assertNotNull($this->checklistItem($assisted, 'story_handoff'));

        config(['publishing.story_handoff.enabled' => false]);
        $this->assertNull($this->checklistItem($assisted, 'story_extras'));
        $this->assertNull($this->checklistItem($assisted, 'story_handoff'));
    }

    // ---------------------------------------------------------------------------------------------
    // Checklist: the soft `story_handoff` item
    // ---------------------------------------------------------------------------------------------

    public function test_story_handoff_warns_when_the_team_posts_it_and_nobody_is_assigned(): void
    {
        $post = $this->storyPost(PostStatus::Draft);
        $this->assertNull($post->assignee_id);
        $this->assertNull($post->created_by);

        $item = $this->checklistItem($post, 'story_handoff');

        $this->assertNotNull($item);
        $this->assertSame('Someone can post this by hand', $item['label']);
        $this->assertFalse($item['passed']);
        $this->assertTrue($item['soft'] ?? false);
        $this->assertStringContainsString('no one is assigned, so everyone on the team will be asked.', $item['detail'] ?? '');

        // A warning never blocks: the extras themselves are complete.
        $this->assertTrue($this->checklistItem($post, 'story_extras')['passed']);
    }

    public function test_story_handoff_passes_when_the_post_has_an_assignee_who_can_work_on_it(): void
    {
        foreach ([Role::Owner, Role::Strategist, Role::Designer] as $role) {
            $post = $this->storyPost(PostStatus::Draft, postAttributes: ['assignee_id' => $this->member($role)->id]);

            $item = $this->checklistItem($post, 'story_handoff');

            $this->assertTrue($item['passed'], "An assigned {$role->value} should count as the poster.");
            $this->assertTrue($item['soft'] ?? false);
            $this->assertArrayNotHasKey('detail', $item);
        }
    }

    public function test_story_handoff_passes_when_only_the_creator_is_known(): void
    {
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['created_by' => $this->member(Role::Designer)->id]);

        $this->assertTrue($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_a_viewer_assignee_does_not_count_as_someone_who_can_post_it(): void
    {
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['assignee_id' => $this->member(Role::Viewer)->id]);

        $item = $this->checklistItem($post, 'story_handoff');

        $this->assertFalse($item['passed']);
        $this->assertStringContainsString('everyone on the team will be asked', $item['detail'] ?? '');

        // Unless the creator can: the assignee is skipped, not the whole post.
        $post->update(['created_by' => $this->member(Role::Strategist)->id]);
        $this->assertTrue($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_an_assignee_from_another_organization_does_not_count(): void
    {
        $stranger = $this->member(Role::Owner, Organization::factory()->create());
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['assignee_id' => $stranger->id]);

        $this->assertFalse($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_story_handoff_warns_when_the_client_posts_it_and_has_no_contact(): void
    {
        $this->client->update(['approval_email' => null]);
        // Somebody is assigned, which is no help when it is the client who posts it.
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['assignee_id' => $this->member(Role::Strategist)->id]);
        $this->storyTarget($post)->update(['handoff_recipient' => StoryHandoffRecipient::Client]);

        $item = $this->checklistItem($post, 'story_handoff');

        $this->assertFalse($item['passed']);
        $this->assertTrue($item['soft'] ?? false);
        $this->assertStringContainsString('Acme Coffee has no contact to send the story to', $item['detail'] ?? '');
        $this->assertStringNotContainsString('everyone on the team', $item['detail'] ?? '');

        // The client's default recipient reads the same as the channel's own choice.
        $this->storyTarget($post)->update(['handoff_recipient' => null]);
        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client]);

        $this->assertFalse($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_story_handoff_passes_when_the_client_has_a_review_email_contact(): void
    {
        $this->client->update(['approval_email' => null]);
        $post = $this->storyPost(PostStatus::Draft);
        $this->storyTarget($post)->update(['handoff_recipient' => StoryHandoffRecipient::Client]);
        $this->assertFalse($this->checklistItem($post, 'story_handoff')['passed']);

        // A billing contact gets no review emails, so does not count.
        ClientContact::factory()->billing()->forClient($this->client)->create();
        $this->assertFalse($this->checklistItem($post, 'story_handoff')['passed']);

        ClientContact::factory()->approver()->forClient($this->client)->create();
        $this->assertTrue($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_story_handoff_passes_when_the_client_has_a_whatsapp_contact(): void
    {
        $this->client->update(['approval_email' => null]);
        $post = $this->storyPost(PostStatus::Draft);
        $this->storyTarget($post)->update(['handoff_recipient' => StoryHandoffRecipient::Client]);

        ClientContact::factory()->forClient($this->client)->create([
            'receives_review_emails' => false,
            'receives_whatsapp' => true,
            'phone' => '+447700900123',
            'whatsapp_consent_at' => now(),
        ]);

        $this->assertTrue($this->checklistItem($post, 'story_handoff')['passed']);
    }

    public function test_story_handoff_does_not_warn_about_assignment_when_the_client_posts_it(): void
    {
        // Nobody assigned, but the client (reachable through its legacy approval email) posts it.
        $post = $this->storyPost(PostStatus::Draft);
        $this->storyTarget($post)->update(['handoff_recipient' => StoryHandoffRecipient::Client]);

        $this->assertTrue($this->checklistItem($post, 'story_handoff')['passed']);
    }

    // ---------------------------------------------------------------------------------------------
    // Checklist: the existing soft `links` item (regression)
    // ---------------------------------------------------------------------------------------------

    public function test_links_no_longer_says_the_link_wont_be_posted_for_a_story_with_a_link_sticker(): void
    {
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['link_url' => 'https://example.com/offer', 'master_caption' => 'Summer sale is on']);

        $item = $this->checklistItem($post, 'links');

        $this->assertTrue($item['passed']);
        $this->assertArrayNotHasKey('detail', $item);
    }

    public function test_links_still_warns_for_a_plain_instagram_feed_channel(): void
    {
        $post = $this->draft();
        $post->update(['link_url' => 'https://example.com/offer', 'master_caption' => 'Summer sale is on']);
        $post->targets()->create(['social_account_id' => $this->instagram->id, 'format' => 'feed']);

        $item = $this->checklistItem($post, 'links');

        $this->assertFalse($item['passed']);
        $this->assertTrue($item['soft'] ?? false);
        $this->assertStringContainsString('Instagram (@acmecoffee): the link won’t be posted', $item['detail'] ?? '');
    }

    public function test_links_still_warns_for_a_story_that_has_no_link_sticker(): void
    {
        $attributes = ['link_url' => 'https://example.com/offer', 'master_caption' => 'Summer sale is on'];

        $plain = $this->storyPost(PostStatus::Draft, extras: [], postAttributes: $attributes);
        $pollOnly = $this->storyPost(PostStatus::Draft, extras: ['poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']]], postAttributes: $attributes);

        foreach ([$plain, $pollOnly] as $post) {
            $item = $this->checklistItem($post, 'links');

            $this->assertFalse($item['passed']);
            $this->assertStringContainsString('the link won’t be posted', $item['detail'] ?? '');
        }

        // With the lane off a link sticker is never added by anyone, so the warning is right again.
        $withSticker = $this->storyPost(PostStatus::Draft, postAttributes: $attributes);
        $this->assertTrue($this->checklistItem($withSticker, 'links')['passed']);

        config(['publishing.story_handoff.enabled' => false]);
        $this->assertFalse($this->checklistItem($withSticker, 'links')['passed']);
    }

    public function test_links_warns_only_about_the_feed_channel_on_a_post_that_has_both(): void
    {
        $post = $this->storyPost(PostStatus::Draft, postAttributes: ['link_url' => 'https://example.com/offer', 'master_caption' => 'Summer sale is on']);
        $feed = $this->instagramAccount('@acmeroastery');
        $post->targets()->create(['social_account_id' => $feed->id, 'format' => 'feed']);

        $item = $this->checklistItem($post, 'links');

        $this->assertFalse($item['passed']);
        $this->assertStringContainsString('Instagram (@acmeroastery): the link won’t be posted', $item['detail'] ?? '');
        $this->assertStringNotContainsString('@acmecoffee', $item['detail'] ?? '');
    }

    // ---------------------------------------------------------------------------------------------
    // Done when: a post with broken extras cannot move to `scheduled`
    // ---------------------------------------------------------------------------------------------

    /**
     * An approved post whose only channel is an Instagram Story, with media attached, so the
     * extras are the only thing that can hold it back.
     *
     * @param  array<string, mixed>  $extras
     */
    private function approvedStoryPost(array $extras): Post
    {
        $post = $this->storyPost(PostStatus::Approved, '2026-11-20 10:00:00', $extras, ['publish_dispatch_id' => null]);
        $asset = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->assets()->attach($asset->id, ['sort_order' => 0]);

        return $post->refresh();
    }

    public function test_a_post_with_broken_extras_cannot_be_scheduled(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $post = $this->approvedStoryPost(['link' => ['url' => '', 'text' => 'Shop now']]);

        // The extras are the only blocker.
        $checklist = (new EvaluatePostChecklistAction)(Post::query()->findOrFail($post->id));
        $blocking = collect($checklist['items'])->filter(fn (array $item) => ! $item['passed'] && ! ($item['soft'] ?? false))->pluck('key')->all();
        $this->assertSame(['story_extras'], array_values($blocking));

        $this->actingAs($this->owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Scheduled->value])
            ->assertSessionHasErrors(['to']);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        Queue::assertNotPushed(PublishPostJob::class);
        Http::assertNothingSent();
    }

    public function test_the_same_post_can_be_scheduled_once_its_extras_are_complete(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $post = $this->approvedStoryPost(['link' => ['url' => '', 'text' => 'Shop now']]);

        $this->actingAs($this->owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Scheduled->value])
            ->assertSessionHasErrors(['to']);

        // Finish the link; nobody is assigned, which only warns.
        app(SyncTargetStoryExtrasAction::class)(
            PostTarget::query()->with('socialAccount')->findOrFail($this->storyTarget($post)->id),
            $this->linkExtras(),
        );

        $this->actingAs($this->owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Scheduled->value])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('posts.edit', $post));

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->postId === $post->id);
        // An assisted Story is never sent to the vendor.
        Http::assertNothingSent();
    }
}
