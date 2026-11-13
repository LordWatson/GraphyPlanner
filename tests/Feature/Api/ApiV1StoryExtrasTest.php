<?php

namespace Tests\Feature\Api;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-5.4 — Story extras over the public API.
 *
 * A story with a link or stickers is posted by a person, not by Alima. An integration has to be
 * able to set that up, read it back, and above all not be told "scheduled" in a way that reads as
 * "will publish itself". And it must never be handed the link that opens a handoff without
 * signing in.
 */
class ApiV1StoryExtrasTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 09:00:00');
        $this->setUpStoryHandoffs();
        $this->member(Role::Owner);
        $this->token = $this->token($this->org, ['read', 'write:drafts', 'write:schedule']);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function token(Organization $org, array $scopes): string
    {
        $plain = 'tok_'.bin2hex(random_bytes(8));

        McpToken::create([
            'org_id' => $org->id,
            'name' => 'story token',
            'token_hash' => McpToken::hashToken($plain),
            'scopes' => $scopes,
        ]);

        return $plain;
    }

    /**
     * @return array<string, string>
     */
    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    /**
     * No response may carry the link that opens a handoff without signing in.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertNoHandoffLink(TestResponse $response): void
    {
        $this->assertStringNotContainsString('/story/', stripslashes((string) $response->getContent()));
    }

    public function test_a_post_returns_each_channels_extras_and_lane(): void
    {
        $post = $this->storyPost(PostStatus::Scheduled);
        $this->addFeedChannel($post);

        $response = $this->getJson("/api/v1/posts/{$post->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.format', 'story')
            ->assertJsonPath('data.channels.0.story_extras.link.url', 'https://example.com/offer')
            ->assertJsonPath('data.channels.0.handoff_recipient', null)
            ->assertJsonPath('data.channels.0.posted_by_hand', true)
            ->assertJsonPath('data.channels.0.handoff_state', 'needs_hand_posting')
            ->assertJsonPath('data.channels.1.story_extras', null)
            ->assertJsonPath('data.channels.1.posted_by_hand', false)
            ->assertJsonPath('data.channels.1.handoff_state', null);

        $this->assertNoHandoffLink($response);

        $list = $this->getJson('/api/v1/posts', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.channels.0.posted_by_hand', true);

        $this->assertNoHandoffLink($list);
    }

    public function test_a_story_that_is_due_reads_ready_to_post_without_its_handoff_link(): void
    {
        Notification::fake();
        $this->refusingAdapter();
        $post = $this->storyPost(utc: '2026-11-20 10:00:00');
        $this->travelTo('2026-11-20 10:01:00');
        $this->startPublishing($post);
        $token = $this->issueToken($this->openHandoff($post));

        $response = $this->getJson("/api/v1/posts/{$post->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.handoff_state', 'ready_to_post');

        $this->assertNoHandoffLink($response);
        $this->assertStringNotContainsString($token, (string) $response->getContent());
    }

    public function test_creating_a_story_channel_with_extras_stores_them_and_marks_it_posted_by_hand(): void
    {
        $account = $this->instagramAccount();

        $response = $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'caption' => 'Offer',
            'channels' => [[
                'social_account_id' => $account->id,
                'scheduled_local_date' => '2026-11-25',
                'scheduled_local_time' => '10:00',
                'format' => 'story',
                'story_extras' => ['link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'], 'mentions' => ['@acmecoffee']],
                'handoff_recipient' => 'team',
            ]],
        ], $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.status', PostStatus::Draft->value)
            ->assertJsonPath('data.channels.0.scheduled_local_time', '10:00')
            ->assertJsonPath('data.channels.0.story_extras.link.text', 'Shop now')
            ->assertJsonPath('data.channels.0.story_extras.mentions', ['acmecoffee'])
            ->assertJsonPath('data.channels.0.handoff_recipient', 'team')
            ->assertJsonPath('data.channels.0.posted_by_hand', true)
            ->assertJsonPath('data.channels.0.handoff_state', null);

        $this->assertNoHandoffLink($response);
        $this->assertTrue($this->storyTarget(Post::query()->whereKey($response->json('data.id'))->firstOrFail())->handoff_required);
    }

    public function test_editing_a_drafts_channels_adds_and_removes_extras(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: []);
        $story = $this->storyTarget($post);
        $this->assertFalse($story->handoff_required);

        $channel = ['social_account_id' => $story->social_account_id, 'scheduled_local_date' => '2026-11-25', 'scheduled_local_time' => '10:00'];

        $this->patchJson("/api/v1/posts/{$post->id}", [
            'channels' => [[...$channel, 'story_extras' => ['poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']]]]],
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.story_extras.poll.options', ['Blue', 'Green'])
            ->assertJsonPath('data.channels.0.posted_by_hand', true);

        // Not mentioning the extras leaves them alone; an explicit null removes them.
        $this->patchJson("/api/v1/posts/{$post->id}", ['channels' => [$channel]], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.posted_by_hand', true);

        $this->patchJson("/api/v1/posts/{$post->id}", ['channels' => [[...$channel, 'story_extras' => null]]], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.story_extras', null)
            ->assertJsonPath('data.channels.0.posted_by_hand', false);

        $this->assertSame('story', $story->refresh()->format);
    }

    public function test_an_integration_can_choose_publish_it_plain(): void
    {
        $account = $this->instagramAccount();

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $account->id, 'format' => 'story', 'story_extras' => [...$this->linkExtras(), 'if_missed' => 'publish_plain']]],
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.channels.0.story_extras.if_missed', 'publish_plain');
    }

    public function test_an_empty_extras_object_clears_them(): void
    {
        $post = $this->storyPost(PostStatus::Draft);
        $story = $this->storyTarget($post);

        // `{}` on the wire. Sent raw: the test client would turn an empty PHP array into `[]`.
        $this->call('PATCH', "/api/v1/posts/{$post->id}", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"channels":[{"social_account_id":'.$story->social_account_id.',"story_extras":{}}]}')
            ->assertOk()
            ->assertJsonPath('data.channels.0.story_extras', null)
            ->assertJsonPath('data.channels.0.posted_by_hand', false);

        $this->assertNull($story->refresh()->story_extras);
        $this->assertFalse($story->handoff_required);
    }

    public function test_extras_on_a_channel_that_is_not_a_story_are_stored_but_it_still_publishes_itself(): void
    {
        $account = $this->instagramAccount();

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $account->id, 'story_extras' => $this->linkExtras()]],
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.channels.0.format', null)
            ->assertJsonPath('data.channels.0.story_extras.link.url', 'https://example.com/offer')
            ->assertJsonPath('data.channels.0.posted_by_hand', false);
    }

    public function test_with_the_kill_switch_off_extras_are_ignored(): void
    {
        config(['publishing.story_handoff.enabled' => false]);
        $account = $this->instagramAccount();

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $account->id, 'format' => 'story', 'story_extras' => $this->linkExtras()]],
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.channels.0.format', 'story')
            ->assertJsonPath('data.channels.0.story_extras', null)
            ->assertJsonPath('data.channels.0.posted_by_hand', false);
    }

    public function test_extras_are_validated_with_the_editors_rules(): void
    {
        $instagram = $this->instagramAccount();
        $linkedin = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::LinkedIn]);

        $create = fn (int $accountId, array $channel) => $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $accountId, ...$channel]],
        ], $this->auth());

        $create($instagram->id, ['format' => 'story', 'story_extras' => ['link' => ['url' => 'http://example.com']]])
            ->assertStatus(422)->assertJsonValidationErrors('channels.0.story_extras.link.url');

        $create($instagram->id, ['format' => 'story', 'story_extras' => ['mentions' => ['a', 'b', 'c', 'd', 'e', 'f']]])
            ->assertStatus(422)->assertJsonValidationErrors('channels.0.story_extras.mentions');

        $create($instagram->id, ['format' => 'story', 'handoff_recipient' => 'nobody'])
            ->assertStatus(422)->assertJsonValidationErrors('channels.0.handoff_recipient');

        $create($instagram->id, ['format' => 'hologram'])
            ->assertStatus(422)->assertJsonValidationErrors('channels.0.format');

        $create($linkedin->id, ['story_extras' => $this->linkExtras()])
            ->assertStatus(422)->assertJsonValidationErrors('channels.0.story_extras');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_a_null_key_means_not_added(): void
    {
        $account = $this->instagramAccount();

        $response = $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [[
                'social_account_id' => $account->id,
                'format' => 'story',
                'story_extras' => [
                    'link' => ['url' => 'https://example.com/offer', 'text' => null],
                    'mentions' => [],
                    'hashtag' => null,
                    'location' => '',
                    'poll' => ['question' => null, 'options' => []],
                ],
            ]],
        ], $this->auth())->assertCreated();

        $response->assertJsonPath('data.channels.0.story_extras', ['version' => 1, 'link' => ['url' => 'https://example.com/offer', 'text' => null]]);

        // Nothing half-added, so nothing for the checklist to complain about.
        $post = Post::query()->whereKey($response->json('data.id'))->firstOrFail();
        $this->assertSame([], $this->storyTarget($post)->storyExtras()->problems());

        $item = collect((new EvaluatePostChecklistAction)($post)['items'])->firstWhere('key', 'story_extras');
        $this->assertNotNull($item);
        $this->assertTrue($item['passed']);
    }

    public function test_only_nulls_on_another_network_is_not_an_error(): void
    {
        $linkedin = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::LinkedIn]);

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $linkedin->id, 'story_extras' => ['link' => null, 'hashtag' => null]]],
        ], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.channels.0.story_extras', null);
    }

    public function test_another_organizations_token_cannot_read_or_write_it(): void
    {
        $post = $this->storyPost(PostStatus::Draft);
        $story = $this->storyTarget($post);

        $otherOrg = Organization::factory()->create();
        Client::factory()->for($otherOrg, 'organization')->create();
        User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $theirs = $this->auth($this->token($otherOrg, ['read', 'write:drafts', 'write:schedule']));

        $read = $this->getJson("/api/v1/posts/{$post->id}", $theirs);
        $this->assertContains($read->status(), [403, 404]);
        $this->assertStringNotContainsString('example.com/offer', (string) $read->getContent());

        $this->getJson('/api/v1/posts', $theirs)->assertOk()->assertJsonCount(0, 'data');

        $write = $this->patchJson("/api/v1/posts/{$post->id}", [
            'channels' => [['social_account_id' => $story->social_account_id, 'story_extras' => null]],
        ], $theirs);
        $this->assertContains($write->status(), [403, 404, 422]);

        // Nor can their own post borrow this organization's Instagram account.
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'channels' => [['social_account_id' => $story->social_account_id, 'format' => 'story', 'story_extras' => $this->linkExtras()]],
        ], $theirs)->assertNotFound();

        $story->refresh();
        $this->assertTrue($story->handoff_required);
        $this->assertSame('https://example.com/offer', $story->storyExtras()->link['url'] ?? null);
    }

    public function test_scheduling_says_which_channel_is_posted_by_hand(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $this->passChecklist();

        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00');
        $this->addFeedChannel($post, '2026-11-22 10:00:00');
        $this->acceptingAdapter();

        $response = $this->postJson("/api/v1/posts/{$post->id}/schedule", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.id', $post->id)
            ->assertJsonPath('data.status', PostStatus::Scheduled->value)
            ->assertJsonPath('data.channels.0.posted_by_hand', true)
            ->assertJsonPath('data.channels.1.posted_by_hand', false)
            ->assertJsonCount(1, 'meta.notes');

        $note = (string) $response->json('meta.notes.0');
        $this->assertStringContainsString('1 channel is a story on Instagram with a link or stickers', $note);
        $this->assertStringContainsString('Alima will not publish it', $note);
        $this->assertStringContainsString('by hand from the Instagram app at 10:00 on 2026-11-22', $note);
        $this->assertStringContainsString('Every other channel publishes automatically.', $note);
        $this->assertNoHandoffLink($response);
    }

    public function test_scheduling_a_post_that_publishes_itself_has_no_note(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $this->passChecklist();
        $this->acceptingAdapter();

        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00', extras: []);

        $this->postJson("/api/v1/posts/{$post->id}/schedule", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.posted_by_hand', false)
            ->assertJsonPath('meta.notes', []);
    }

    /**
     * The gate itself is covered by `ApiV1WritesTest`; here it only has to be open.
     */
    private function passChecklist(): void
    {
        $this->mock(EvaluatePostChecklistAction::class)
            ->shouldReceive('__invoke')
            ->andReturn(['passed' => true, 'items' => []]);
    }
}
