<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\IngestCommentsForTargetAction;
use App\Actions\Inbox\SyncOrganizationCommentsAction;
use App\Contracts\CommentSource;
use App\Enums\CommentSentiment;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Inbox\CommentThreadReading;
use App\Support\Inbox\InboundComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inbox\Concerns\FakeCommentSource;
use Tests\TestCase;

class CommentIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function target(Organization $org, Platform $platform = Platform::Instagram): PostTarget
    {
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => $platform,
            'connection_status' => ConnectionStatus::Connected,
            'external_account_id' => 'profile-'.$client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Published]);

        return $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'external_post_id' => 'job-'.$post->id,
            'platform_post_id' => 'ig_post_'.$post->id,
            'published_at' => now()->subDay(),
        ]);
    }

    private function bindFake(FakeCommentSource $fake): void
    {
        $this->app->instance(CommentSource::class, $fake);
    }

    public function test_it_ingests_comments_into_threaded_conversations_with_sentiment(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $target = $this->target($org);

        $fake = new FakeCommentSource([
            $target->id => new CommentThreadReading([
                new InboundComment(externalId: 'c1', body: 'How much is shipping?', authorName: 'Ada', postedAt: now()->subHours(3)),
                new InboundComment(externalId: 'c2', body: 'Reply from a fan ❤️', parentExternalId: 'c1', authorName: 'Bea', postedAt: now()->subHours(2)),
                new InboundComment(externalId: 'c3', body: 'Totally separate, love this!', authorName: 'Cy', postedAt: now()->subHour()),
            ]),
        ]);
        $this->bindFake($fake);

        $summary = app(IngestCommentsForTargetAction::class)($target->fresh());

        $this->assertSame(2, $summary['conversations']);
        $this->assertSame(3, $summary['messages']);
        $this->assertSame(2, InboxConversation::query()->count());
        $this->assertSame(3, InboxMessage::query()->count());

        $thread = InboxConversation::query()->where('external_conversation_id', 'c1')->firstOrFail();
        $this->assertSame(2, $thread->messages()->count());
        $this->assertSame(CommentSentiment::Question, $thread->sentiment);
        // Last message in the thread is an inbound non-question reply, so no studio reply is owed.
        $this->assertFalse($thread->is_reply_needed);
        $this->assertSame($org->id, $thread->org_id);
    }

    public function test_a_trailing_question_marks_the_thread_reply_needed(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $target = $this->target($org);

        $this->bindFake(new FakeCommentSource([
            $target->id => new CommentThreadReading([
                new InboundComment(externalId: 'c1', body: 'Great post', postedAt: now()->subHours(2)),
                new InboundComment(externalId: 'c2', body: 'Do you ship to Dubai?', parentExternalId: 'c1', postedAt: now()->subHour()),
            ]),
        ]));

        app(IngestCommentsForTargetAction::class)($target->fresh());

        $thread = InboxConversation::query()->where('external_conversation_id', 'c1')->firstOrFail();
        $this->assertTrue($thread->is_reply_needed);
        $this->assertSame(CommentSentiment::Question, $thread->sentiment);
    }

    public function test_ingestion_is_idempotent(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $target = $this->target($org);

        $this->bindFake(new FakeCommentSource([
            $target->id => new CommentThreadReading([
                new InboundComment(externalId: 'c1', body: 'Nice'),
            ]),
        ]));

        app(IngestCommentsForTargetAction::class)($target->fresh());
        $second = app(IngestCommentsForTargetAction::class)($target->fresh());

        $this->assertSame(0, $second['messages']);
        $this->assertSame(1, InboxMessage::query()->count());
        $this->assertSame(1, InboxConversation::query()->count());
    }

    public function test_sync_skips_platforms_without_comment_support(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $pinterest = $this->target($org, Platform::Pinterest);

        $fake = new FakeCommentSource([
            $pinterest->id => new CommentThreadReading([new InboundComment(externalId: 'p1', body: 'hi')]),
        ]);
        $this->bindFake($fake);

        $summary = app(SyncOrganizationCommentsAction::class)($org->fresh());

        $this->assertSame(0, $summary['targets']);
        $this->assertSame([], $fake->fetchCalls);
        $this->assertSame(0, InboxConversation::query()->count());
    }

    public function test_sync_stamps_synced_at_and_records_a_vendor_failure(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $target = $this->target($org);

        $this->bindFake(new FakeCommentSource(error: 'Upload-Post is down'));

        $summary = app(SyncOrganizationCommentsAction::class)($org->fresh());

        $this->assertSame(1, $summary['targets']);
        $this->assertSame(1, $summary['failed']);
        $this->assertNotNull($target->fresh()->comments_synced_at);
    }

    public function test_sync_skips_a_suspended_org(): void
    {
        $org = Organization::factory()->suspended()->create(['upload_post_key' => 'up-key']);
        $target = $this->target($org);

        $fake = new FakeCommentSource([$target->id => new CommentThreadReading([new InboundComment(externalId: 'c1', body: 'hi')])]);
        $this->bindFake($fake);

        $summary = app(SyncOrganizationCommentsAction::class)($org->fresh());

        $this->assertSame('suspended', $summary['skipped']);
        $this->assertSame([], $fake->fetchCalls);
    }
}
