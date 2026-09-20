<?php

namespace Tests\Unit\Actions\Publishing;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPublishStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_single_target_post_published_when_the_vendor_confirms_success(): void
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

        (new SyncPublishStatusAction)($target, true, null);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $target->refresh()->status);
    }

    public function test_it_marks_the_post_failed_when_the_vendor_reports_a_failure(): void
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

        (new SyncPublishStatusAction)($target, false, 'Vendor rejected the request');

        $post->refresh();
        $target->refresh();

        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('Vendor rejected the request', $target->error);
    }

    public function test_it_waits_for_every_target_before_finalizing_the_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $accountA = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $accountB = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::Facebook]);
        $targetA = $post->targets()->create([
            'social_account_id' => $accountA->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-a',
        ]);
        $post->targets()->create([
            'social_account_id' => $accountB->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-b',
        ]);

        (new SyncPublishStatusAction)($targetA, true, null);

        // Post must stay `publishing` — the second target hasn't reported in yet.
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_it_ignores_a_target_whose_post_already_moved_on(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-123',
        ]);

        (new SyncPublishStatusAction)($target, true, null);

        // The target row itself is still updated, but the already-failed post isn't re-transitioned.
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $target->refresh()->status);
    }
}
