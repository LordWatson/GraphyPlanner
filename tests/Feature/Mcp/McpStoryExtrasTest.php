<?php

namespace Tests\Feature\Mcp;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\CreateDraftPostTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-5.4 — Story extras over MCP.
 *
 * An assistant can set a story's link and stickers up and read them back, under the editor's own
 * rules. What it must never do is tell its user that such a story "will publish itself": a person
 * posts it from the Instagram app. And nothing here hands an assistant the handoff link, or a way
 * to say the story was posted.
 */
class McpStoryExtrasTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 09:00:00');
        $this->setUpStoryHandoffs();
        $this->org->update(['default_timezone' => 'UTC']);
        config()->set('mcp.spike.dev_org_id', $this->org->id);
        $this->member(Role::Owner);
    }

    public function test_get_post_returns_each_targets_extras_and_lane(): void
    {
        $post = $this->storyPost(PostStatus::Scheduled);
        $this->addFeedChannel($post);

        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertDontSee(['/story/', '\/story\/'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('targets.0.format', 'story')
                ->where('targets.0.story_extras.link.url', 'https://example.com/offer')
                ->where('targets.0.handoff_recipient', null)
                ->where('targets.0.posted_by_hand', true)
                ->where('targets.0.handoff_state', 'needs_hand_posting')
                ->where('targets.1.story_extras', null)
                ->where('targets.1.posted_by_hand', false)
                ->where('targets.1.handoff_state', null)
                ->etc());
    }

    public function test_get_post_says_ready_to_post_without_the_handoff_link(): void
    {
        Notification::fake();
        $this->refusingAdapter();
        $post = $this->storyPost(utc: '2026-11-20 10:00:00');
        $this->travelTo('2026-11-20 10:01:00');
        $this->startPublishing($post);
        $token = $this->issueToken($this->openHandoff($post));

        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertDontSee(['/story/', '\/story\/', $token])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('targets.0.handoff_state', 'ready_to_post')->etc());
    }

    public function test_create_draft_post_stores_extras_and_marks_the_story_posted_by_hand(): void
    {
        $account = $this->instagramAccount();

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'master_caption' => 'Offer',
            'targets' => [[
                'social_account_id' => $account->id,
                'scheduled_local_date' => '2026-11-25',
                'scheduled_local_time' => '10:00',
                'format' => 'story',
                'story_extras' => ['link' => ['url' => 'https://example.com/offer'], 'hashtag' => '#summersale'],
                'handoff_recipient' => 'client',
            ]],
        ])
            ->assertOk()
            ->assertSee('by hand')
            ->assertDontSee(['/story/', '\/story\/']);

        $post = Post::query()->where('org_id', $this->org->id)->firstOrFail();
        $story = $this->storyTarget($post);

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertTrue($story->handoff_required);
        $this->assertSame('summersale', $story->storyExtras()->hashtag);
        $this->assertSame('client', $story->handoff_recipient?->value);
    }

    public function test_update_post_adds_extras_and_null_removes_them(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: []);
        $story = $this->storyTarget($post);
        $target = ['social_account_id' => $story->social_account_id, 'scheduled_local_date' => '2026-11-25', 'scheduled_local_time' => '10:00'];

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'targets' => [[...$target, 'story_extras' => ['mentions' => ['@acmecoffee']]]],
        ])->assertOk()->assertSee('by hand');

        $this->assertTrue($story->refresh()->handoff_required);
        $this->assertSame(['acmecoffee'], $story->storyExtras()->mentions);

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'targets' => [[...$target, 'story_extras' => null]],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('note', null)->etc());

        $this->assertFalse($story->refresh()->handoff_required);
        $this->assertNull($story->story_extras);
    }

    public function test_update_post_clears_extras_sent_as_an_empty_object(): void
    {
        $post = $this->storyPost(PostStatus::Draft);
        $story = $this->storyTarget($post);

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'targets' => [['social_account_id' => $story->social_account_id, 'story_extras' => []]],
        ])->assertOk();

        $this->assertNull($story->refresh()->story_extras);
        $this->assertFalse($story->handoff_required);
    }

    public function test_an_assistant_cannot_choose_publish_it_plain(): void
    {
        $account = $this->instagramAccount();

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'targets' => [['social_account_id' => $account->id, 'format' => 'story', 'story_extras' => [...$this->linkExtras(), 'if_missed' => 'publish_plain']]],
        ])->assertOk();

        $post = Post::query()->where('org_id', $this->org->id)->firstOrFail();
        $story = $this->storyTarget($post);
        $this->assertTrue($story->handoff_required);
        $this->assertFalse($story->storyExtras()->publishesPlainIfMissed());

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'targets' => [['social_account_id' => $account->id, 'story_extras' => [...$this->linkExtras(), 'if_missed' => 'publish_plain']]],
        ])->assertOk();

        $this->assertFalse($story->refresh()->storyExtras()->publishesPlainIfMissed());
    }

    public function test_an_update_keeps_a_persons_publish_it_plain_choice(): void
    {
        $post = $this->storyPost(PostStatus::Draft, extras: [...$this->linkExtras(), 'if_missed' => 'publish_plain']);
        $story = $this->storyTarget($post);
        $this->assertTrue($story->storyExtras()->publishesPlainIfMissed());

        // The assistant changes the link and says nothing (or `fail`) about the fallback.
        foreach ([[], ['if_missed' => 'fail']] as $sent) {
            GraphyServer::tool(UpdatePostTool::class, [
                'post_id' => $post->id,
                'targets' => [['social_account_id' => $story->social_account_id, 'story_extras' => ['link' => ['url' => 'https://example.com/new'], ...$sent]]],
            ])->assertOk();

            $story->refresh();
            $this->assertSame('https://example.com/new', $story->storyExtras()->link['url'] ?? null);
            $this->assertTrue($story->storyExtras()->publishesPlainIfMissed());
        }
    }

    public function test_with_the_kill_switch_off_extras_are_ignored(): void
    {
        config(['publishing.story_handoff.enabled' => false]);
        $account = $this->instagramAccount();

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'targets' => [['social_account_id' => $account->id, 'format' => 'story', 'story_extras' => $this->linkExtras()]],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('note', null)->etc());

        $story = $this->storyTarget(Post::query()->where('org_id', $this->org->id)->firstOrFail());
        $this->assertNull($story->story_extras);
        $this->assertFalse($story->handoff_required);
    }

    public function test_extras_are_validated_with_the_editors_rules(): void
    {
        $instagram = $this->instagramAccount();
        $linkedin = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::LinkedIn]);

        $create = fn (int $accountId, array $target) => GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'targets' => [['social_account_id' => $accountId, ...$target]],
        ]);

        $create($instagram->id, ['format' => 'story', 'story_extras' => ['link' => ['url' => 'http://example.com']]])
            ->assertHasErrors()->assertSee('https://');

        $create($instagram->id, ['format' => 'story', 'story_extras' => ['mentions' => ['a', 'b', 'c', 'd', 'e', 'f']]])
            ->assertHasErrors()->assertSee('mention up to 5');

        $create($linkedin->id, ['story_extras' => $this->linkExtras()])
            ->assertHasErrors()->assertSee('only available on Instagram stories');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_a_null_key_means_not_added(): void
    {
        $account = $this->instagramAccount();

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'targets' => [[
                'social_account_id' => $account->id,
                'format' => 'story',
                'story_extras' => ['link' => ['url' => 'https://example.com/offer', 'text' => ''], 'hashtag' => null, 'location' => '', 'mentions' => [], 'poll' => null],
            ]],
        ])->assertOk();

        $post = Post::query()->where('org_id', $this->org->id)->firstOrFail();
        $story = $this->storyTarget($post);

        $this->assertSame(['version' => 1, 'link' => ['url' => 'https://example.com/offer', 'text' => null]], $story->story_extras);
        $this->assertSame([], $story->storyExtras()->problems());

        // The checklist get_post reports has nothing to say about a hashtag nobody added.
        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id, 'include_media' => false])
            ->assertOk()
            ->assertDontSee('needs a tag');
    }

    public function test_another_organizations_connection_cannot_read_or_write_it(): void
    {
        $post = $this->storyPost(PostStatus::Draft);
        $story = $this->storyTarget($post);

        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $this->app->instance(McpContext::class, new McpContext(
            organization: $otherOrg,
            scopes: ['read', 'write:drafts', 'write:schedule'],
        ));

        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])
            ->assertHasErrors()
            ->assertDontSee('example.com/offer');

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'targets' => [['social_account_id' => $story->social_account_id, 'story_extras' => null]],
        ])->assertHasErrors();

        // Nor can their own draft borrow this organization's Instagram account.
        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $otherClient->id,
            'targets' => [['social_account_id' => $story->social_account_id, 'format' => 'story', 'story_extras' => $this->linkExtras()]],
        ])->assertHasErrors();

        $story->refresh();
        $this->assertTrue($story->handoff_required);
        $this->assertSame('https://example.com/offer', $story->storyExtras()->link['url'] ?? null);
    }

    public function test_schedule_post_says_a_person_posts_the_story_by_hand(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $this->passChecklist();
        $this->acceptingAdapter();

        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00');
        $this->addFeedChannel($post, '2026-11-22 10:00:00');

        GraphyServer::tool(SchedulePostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertSee(['Alima will not publish it', 'by hand from the Instagram app at 10:00 on 2026-11-22', 'Every other channel publishes automatically.'])
            ->assertDontSee(['It will publish automatically at the scheduled time', '/story/', '\/story\/']);

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
    }

    public function test_schedule_post_has_no_note_when_everything_publishes_itself(): void
    {
        Http::fake();
        Queue::fake([PublishPostJob::class]);
        $this->passChecklist();
        $this->acceptingAdapter();

        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00', extras: []);

        GraphyServer::tool(SchedulePostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertSee('It will publish automatically at the scheduled time')
            ->assertDontSee('by hand')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('note', null)->etc());
    }

    public function test_no_tool_can_say_a_story_was_posted(): void
    {
        // An assistant may set a story up; only a person says it went out.
        foreach ((new \ReflectionProperty(GraphyServer::class, 'tools'))->getDefaultValue() as $tool) {
            $this->assertDoesNotMatchRegularExpression('/Handoff|MarkPosted|Complete/i', class_basename($tool));
        }
    }

    /**
     * The gate itself is covered by `McpScheduleToolsTest`; here it only has to be open.
     */
    private function passChecklist(): void
    {
        $this->mock(EvaluatePostChecklistAction::class)
            ->shouldReceive('__invoke')
            ->andReturn(['passed' => true, 'items' => []]);
    }
}
