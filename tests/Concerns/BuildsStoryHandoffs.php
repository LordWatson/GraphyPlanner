<?php

namespace Tests\Concerns;

use App\Actions\Stories\IssueStoryHandoffLinkAction;
use App\Actions\Stories\SyncTargetStoryExtrasAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Support\Publishing\TargetResult;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;

/**
 * Phase S fixtures: an agency, a client, and posts with an assisted Instagram Story channel (a
 * Story with extras, which is posted by hand instead of going to the vendor).
 *
 * Call `setUpStoryHandoffs()` from `setUp()`. It switches the lane's kill switch on, since it
 * ships off by default.
 */
trait BuildsStoryHandoffs
{
    protected Organization $org;

    protected Client $client;

    protected function setUpStoryHandoffs(): void
    {
        config(['publishing.story_handoff.enabled' => true]);

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme Coffee']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function linkExtras(string $url = 'https://example.com/offer', ?string $text = 'Shop now'): array
    {
        return ['version' => 1, 'link' => ['url' => $url, 'text' => $text]];
    }

    protected function member(Role $role = Role::Strategist, ?Organization $org = null): User
    {
        return User::factory()->for($org ?? $this->org, 'organization')->role($role)->create();
    }

    protected function instagramAccount(string $handle = '@acmecoffee', string $timezone = 'UTC'): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'handle' => $handle,
            'timezone' => $timezone,
        ]);
    }

    /**
     * A post with one Instagram Story channel carrying extras (so it takes the assisted lane),
     * on schedule `dispatch-1`.
     *
     * @param  array<string, mixed>|null  $extras  defaults to a link sticker; `[]` for a plain Story
     * @param  array<string, mixed>  $postAttributes
     */
    protected function storyPost(
        PostStatus $status = PostStatus::Scheduled,
        string $utc = '2026-11-20 10:00:00',
        ?array $extras = null,
        array $postAttributes = [],
    ): Post {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => $status,
            'publish_dispatch_id' => 'dispatch-1',
            ...$postAttributes,
        ]);

        $this->addStoryChannel($post, $utc, $extras);

        return $post->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $extras
     */
    protected function addStoryChannel(Post $post, string $utc = '2026-11-20 10:00:00', ?array $extras = null, ?SocialAccount $account = null): PostTarget
    {
        $account ??= $this->instagramAccount();

        /** @var PostTarget $target */
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'format' => 'story',
            'scheduled_local_date' => substr($utc, 0, 10),
            'scheduled_local_time' => substr($utc, 11, 5),
            'scheduled_at_utc' => $utc,
        ]);

        return app(SyncTargetStoryExtrasAction::class)($target, $extras ?? $this->linkExtras());
    }

    /**
     * A plain (auto-publishing) channel on another network, next to the Story.
     */
    protected function addFeedChannel(Post $post, string $utc = '2026-11-20 10:00:00', Platform $platform = Platform::LinkedIn): PostTarget
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
            'timezone' => 'UTC',
        ]);

        /** @var PostTarget $target */
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => substr($utc, 0, 10),
            'scheduled_local_time' => substr($utc, 11, 5),
            'scheduled_at_utc' => $utc,
        ]);

        return $target;
    }

    /**
     * Run the publish job for the post's schedule, so the Story channel gets its handoff.
     */
    protected function startPublishing(Post $post): Post
    {
        $this->app->call([new PublishPostJob($post->id, (string) $post->publish_dispatch_id), 'handle']);

        return $post->refresh();
    }

    protected function storyTarget(Post $post): PostTarget
    {
        return $post->targets()->where('format', 'story')->firstOrFail();
    }

    protected function openHandoff(Post $post): StoryHandoff
    {
        return StoryHandoff::query()->where('post_id', $post->id)->open()->firstOrFail();
    }

    /**
     * The plain token of a freshly issued link for the handoff (what the email carries).
     */
    protected function issueToken(StoryHandoff $handoff): string
    {
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        return (string) last(explode('/', (string) parse_url($url, PHP_URL_PATH)));
    }

    /**
     * A vendor that accepts whatever it is sent, recording which accounts it was asked to publish.
     *
     * @param  list<int>  $sent  filled with the social account ids the vendor received
     */
    protected function acceptingAdapter(array &$sent = []): MockInterface
    {
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->andReturnUsing(function (Post $post, Collection $accounts) use (&$sent) {
            foreach ($accounts as $account) {
                $sent[] = $account->id;
            }

            return $accounts->map(fn (SocialAccount $account) => new TargetResult(accountId: $account->id, ok: true, externalPostId: 'job-'.$account->id));
        });
        $this->app->instance(PublishAdapter::class, $adapter);

        return $adapter;
    }

    protected function refusingAdapter(): MockInterface
    {
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        return $adapter;
    }
}
