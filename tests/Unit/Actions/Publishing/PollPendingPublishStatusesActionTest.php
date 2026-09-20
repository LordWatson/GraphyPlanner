<?php

namespace Tests\Unit\Actions\Publishing;

use App\Actions\Publishing\PollPendingPublishStatusesAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PollPendingPublishStatusesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finalizes_a_pending_target_the_vendor_has_resolved(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-123',
        ]);
        $target->timestamps = false;
        $target->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('checkStatus')
            ->once()
            ->withArgs(fn (SocialAccount $a, string $externalPostId) => $a->id === $account->id && $externalPostId === 'up-123')
            ->andReturn(new TargetResult(accountId: $account->id, ok: true, externalPostId: 'up-123'));

        $checked = (new PollPendingPublishStatusesAction)($adapter, app(SyncPublishStatusAction::class), 5);

        $this->assertSame(1, $checked);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $target->refresh()->status);
    }

    public function test_it_leaves_a_target_pending_when_the_vendor_has_no_verdict_yet(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-123',
        ]);
        $target->timestamps = false;
        $target->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('checkStatus')->once()->andReturn(null);

        (new PollPendingPublishStatusesAction)($adapter, app(SyncPublishStatusAction::class), 5);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $target->refresh()->status);
    }

    public function test_it_skips_targets_that_have_not_been_pending_long_enough(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-123',
            'updated_at' => now(),
        ]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('checkStatus');

        $checked = (new PollPendingPublishStatusesAction)($adapter, app(SyncPublishStatusAction::class), 5);

        $this->assertSame(0, $checked);
    }
}
