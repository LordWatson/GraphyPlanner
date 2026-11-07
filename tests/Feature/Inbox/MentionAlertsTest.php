<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\IngestCommentsForTargetAction;
use App\Contracts\CommentSource;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\MentionKeyword;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\MentionAlert;
use App\Services\Inbox\MentionAlerts;
use App\Support\Inbox\CommentThreadReading;
use App\Support\Inbox\InboundComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Inbox\Concerns\FakeCommentSource;
use Tests\TestCase;

/**
 * Mention alerts — keyword watching over the comments the app already ingests.
 *
 * The narrow, honest version of "social listening": nothing is crawled. The tests pin what makes it
 * worth having rather than annoying: it matches whole words only, it fires once per comment, and it
 * never reaches across organisations.
 */
class MentionAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['upload_post_key' => 'up-key']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function watch(string $term): MentionKeyword
    {
        return MentionKeyword::create([
            'org_id' => $this->org->id,
            'created_by' => $this->owner->id,
            'term' => $term,
        ]);
    }

    private function target(): PostTarget
    {
        $client = Client::factory()->for($this->org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'connection_status' => ConnectionStatus::Connected,
            'external_account_id' => 'profile-'.$client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Published]);

        return $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'external_post_id' => 'job-'.$post->id,
            'platform_post_id' => 'ig_post_'.$post->id,
            'published_at' => now()->subDay(),
        ]);
    }

    private function ingest(PostTarget $target, string $body, string $externalId = 'c1'): void
    {
        $this->app->instance(CommentSource::class, new FakeCommentSource([
            $target->id => new CommentThreadReading([
                new InboundComment(externalId: $externalId, body: $body, authorName: 'Ada', postedAt: now()->subHour()),
            ]),
        ]));

        app(IngestCommentsForTargetAction::class)($target->fresh());
    }

    // --- matching ------------------------------------------------------------------------------

    public function test_it_matches_a_whole_word_whatever_the_case(): void
    {
        $this->assertTrue(MentionAlerts::mentions('Can I get a REFUND please?', 'refund'));
        $this->assertTrue(MentionAlerts::mentions('refund!', 'Refund'));
    }

    /**
     * The failure that would get this feature switched off within a day.
     */
    public function test_it_does_not_match_a_word_inside_another_word(): void
    {
        $this->assertFalse(MentionAlerts::mentions('We will start tomorrow', 'art'));
        $this->assertFalse(MentionAlerts::mentions('refunded last week', 'refund'));
    }

    public function test_a_phrase_matches_as_written(): void
    {
        $this->assertTrue(MentionAlerts::mentions('this is a total waste of money', 'waste of money'));
        $this->assertFalse(MentionAlerts::mentions('waste money', 'waste of money'));
    }

    public function test_a_term_next_to_punctuation_or_an_emoji_still_counts(): void
    {
        $this->assertTrue(MentionAlerts::mentions('love the #acme drop 🔥', 'acme'));
        $this->assertTrue(MentionAlerts::mentions('(acme)', 'acme'));
    }

    public function test_a_blank_term_matches_nothing(): void
    {
        $this->assertFalse(MentionAlerts::mentions('anything at all', '   '));
    }

    // --- alerting ------------------------------------------------------------------------------

    public function test_a_new_comment_using_a_watched_word_alerts_the_studio(): void
    {
        Notification::fake();
        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $keyword = $this->watch('refund');

        $this->ingest($this->target(), 'Where is my refund?');

        Notification::assertSentTo($this->owner, MentionAlert::class);
        Notification::assertSentTo($strategist, MentionAlert::class);
        // A designer answers comments but does not need every brand alert.
        Notification::assertNotSentTo($designer, MentionAlert::class);
        $this->assertSame(1, $keyword->fresh()->match_count);
    }

    public function test_a_comment_without_a_watched_word_alerts_nobody(): void
    {
        Notification::fake();
        $this->watch('refund');

        $this->ingest($this->target(), 'Love this, where can I buy it?');

        Notification::assertNothingSent();
    }

    public function test_an_organization_watching_nothing_costs_nothing(): void
    {
        Notification::fake();

        $this->ingest($this->target(), 'Where is my refund?');

        Notification::assertNothingSent();
    }

    /**
     * The sweep re-reads recent posts every hour, so a comment it has already stored must not
     * produce the same alert again.
     */
    public function test_re_ingesting_the_same_comment_does_not_alert_twice(): void
    {
        Notification::fake();
        $keyword = $this->watch('refund');
        $target = $this->target();

        $this->ingest($target, 'Where is my refund?');
        $this->ingest($target, 'Where is my refund?');

        Notification::assertSentToTimes($this->owner, MentionAlert::class, 1);
        $this->assertSame(1, $keyword->fresh()->match_count);
    }

    public function test_another_organizations_watched_words_are_never_applied(): void
    {
        Notification::fake();
        $otherOrg = Organization::factory()->create();
        MentionKeyword::create(['org_id' => $otherOrg->id, 'term' => 'refund']);

        $this->ingest($this->target(), 'Where is my refund?');

        Notification::assertNothingSent();
    }

    public function test_the_alert_names_the_word_and_links_to_the_inbox(): void
    {
        Notification::fake();
        $this->watch('refund');

        $this->ingest($this->target(), 'Where is my refund?');

        Notification::assertSentTo($this->owner, MentionAlert::class, function (MentionAlert $notification) {
            $payload = $notification->toArray($this->owner);

            $this->assertStringContainsString('refund', $payload['title']);
            $this->assertStringContainsString('/inbox', $payload['url']);
            // The comment body belongs in the inbox, not copied into a notification row.
            $this->assertStringNotContainsString('Where is my', $payload['body']);

            return true;
        });
    }

    // --- managing the list ---------------------------------------------------------------------

    public function test_an_owner_can_add_and_remove_a_word(): void
    {
        $this->actingAs($this->owner)->post(route('inbox.alerts.store'), ['term' => 'refund'])
            ->assertRedirect();

        $keyword = MentionKeyword::query()->withoutGlobalScopes()->where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame('refund', $keyword->term);

        $this->actingAs($this->owner)->delete(route('inbox.alerts.destroy', $keyword))->assertRedirect();
        $this->assertDatabaseMissing('mention_keywords', ['id' => $keyword->id]);
    }

    public function test_the_page_lists_what_is_watched(): void
    {
        $this->watch('refund');

        $this->actingAs($this->owner)->get(route('inbox.alerts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inbox/alerts')
                ->where('keywords.0.term', 'refund')
                ->where('can.manage', true));
    }

    public function test_a_viewer_can_look_but_not_add(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)->get(route('inbox.alerts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', false));

        $this->actingAs($viewer)->post(route('inbox.alerts.store'), ['term' => 'refund'])->assertForbidden();
    }

    public function test_another_organizations_word_cannot_be_removed(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = MentionKeyword::create(['org_id' => $otherOrg->id, 'term' => 'refund']);

        $this->actingAs($this->owner)->delete(route('inbox.alerts.destroy', $theirs))->assertForbidden();
        $this->assertDatabaseHas('mention_keywords', ['id' => $theirs->id]);
    }

    public function test_the_same_word_twice_is_refused_but_two_organizations_may_watch_it(): void
    {
        $this->watch('refund');

        $this->actingAs($this->owner)->post(route('inbox.alerts.store'), ['term' => 'refund'])
            ->assertSessionHasErrors('term');

        $otherOrg = Organization::factory()->create();
        $theirOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $this->actingAs($theirOwner)->post(route('inbox.alerts.store'), ['term' => 'refund'])
            ->assertSessionHasNoErrors();
    }

    /**
     * Every term is a regex against every new comment, so the list has a ceiling — and says so
     * rather than silently ignoring what is past it.
     */
    public function test_the_list_has_a_ceiling(): void
    {
        for ($i = 0; $i < MentionKeyword::MAX_PER_ORG; $i++) {
            $this->watch("term-{$i}");
        }

        $this->actingAs($this->owner)->post(route('inbox.alerts.store'), ['term' => 'one-too-many'])
            ->assertSessionHasErrors('term');
    }

    public function test_a_single_letter_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('inbox.alerts.store'), ['term' => 'a'])
            ->assertSessionHasErrors('term');
    }
}
