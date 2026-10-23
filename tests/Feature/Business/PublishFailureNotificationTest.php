<?php

namespace Tests\Feature\Business;

use App\Actions\Publishing\ResolvePostPublishOutcomeAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step B-5.3 — a business workspace is emailed as well as pinged in-app when a publish fails, so a
 * solo owner never misses it; an agency keeps the in-app bell it has always had.
 */
class PublishFailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Post}
     */
    private function failedPost(Organization $org): array
    {
        $brand = $org->isBusiness()
            ? Client::factory()->for($org, 'organization')->primary()->create()
            : Client::factory()->for($org, 'organization')->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $account = SocialAccount::factory()->for($brand)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Failed,
            'error' => 'The access token has expired, please reconnect.',
        ]);

        return [$org, $owner, $post->refresh()];
    }

    public function test_a_business_failure_also_sends_an_email(): void
    {
        Notification::fake();
        [, $owner, $post] = $this->failedPost(Organization::factory()->business()->create());

        app(ResolvePostPublishOutcomeAction::class)($post);

        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
        Notification::assertSentTo(
            $owner,
            PostPublishFailed::class,
            fn (PostPublishFailed $notification, array $channels) => in_array('mail', $channels, true)
                && in_array('database', $channels, true),
        );
    }

    public function test_an_agency_failure_stays_in_app_only(): void
    {
        Notification::fake();
        [, $owner, $post] = $this->failedPost(Organization::factory()->create());

        app(ResolvePostPublishOutcomeAction::class)($post);

        Notification::assertSentTo(
            $owner,
            PostPublishFailed::class,
            fn (PostPublishFailed $notification, array $channels) => $channels === ['database'],
        );
    }
}
