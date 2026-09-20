<?php

namespace Tests\Unit\Jobs;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class PublishPostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_every_target_and_marks_the_post_published_when_all_targets_succeed(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Scheduled]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_at_utc' => now(),
        ]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')
            ->once()
            ->withArgs(fn (Post $publishedPost, Collection $targets) => $publishedPost->id === $post->id && $targets->pluck('id')->all() === [$account->id])
            ->andReturn(collect([
                new TargetResult(accountId: $account->id, ok: true, externalPostId: 'ext-123'),
            ]));
        $this->app->instance(PublishAdapter::class, $adapter);

        (new PublishPostJob($post->id))->handle($adapter, app(TransitionPostStatusAction::class));

        $post->refresh();
        $target->refresh();

        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(PostTargetStatus::Published, $target->status);
        $this->assertSame('ext-123', $target->external_post_id);
        $this->assertNull($target->error);
    }

    public function test_it_marks_the_post_and_target_failed_when_a_target_fails(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Scheduled]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_at_utc' => now(),
        ]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->once()->andReturn(collect([
            new TargetResult(accountId: $account->id, ok: false, error: 'Vendor rejected the request'),
        ]));

        (new PublishPostJob($post->id))->handle($adapter, app(TransitionPostStatusAction::class));

        $post->refresh();
        $target->refresh();

        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('Vendor rejected the request', $target->error);

        // The vendor error must be recorded on the activity log entry, not only on the target,
        // so it's visible in one place alongside every other status change.
        $log = $post->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame(PostStatus::Failed, $log->to_status);
        $this->assertStringContainsString('Vendor rejected the request', (string) $log->note);
    }

    public function test_it_skips_a_post_that_is_no_longer_scheduled(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');

        (new PublishPostJob($post->id))->handle($adapter, app(TransitionPostStatusAction::class));

        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }
}
