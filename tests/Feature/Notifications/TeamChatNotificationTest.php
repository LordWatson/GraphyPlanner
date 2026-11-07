<?php

namespace Tests\Feature\Notifications;

use App\Contracts\ChatNotifier;
use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostCommentAdded;
use App\Services\Notifications\NullChatNotifier;
use App\Services\Notifications\SlackChatNotifier;
use App\Services\PostNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Team-chat delivery. `ChatNotifier` had only a no-op implementation, so every caller that
 * believed it was telling the team something was talking to nothing.
 *
 * The webhook URL here is a fixture; it is never called for real because `Http::fake()` intercepts
 * every request.
 */
class TeamChatNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.test/services/T000/B000/xyz';

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // The organization settings page holds the API keys, so viewing or changing it re-checks
        // the password (Step I-16.2). Not what these tests are about.
        $this->confirmPassword();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function approvedPost(): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
        ]);
    }

    public function test_slack_is_the_bound_implementation_by_default(): void
    {
        $this->assertInstanceOf(SlackChatNotifier::class, app(ChatNotifier::class));
    }

    public function test_it_can_be_turned_off_entirely_by_config(): void
    {
        config(['notifications.chat' => 'null']);

        $this->assertInstanceOf(NullChatNotifier::class, app(ChatNotifier::class));
    }

    public function test_an_organization_without_a_webhook_is_never_notified(): void
    {
        $this->assertFalse(app(ChatNotifier::class)->isConfiguredFor($this->org));

        app(ChatNotifier::class)->notifyOrganization($this->org, 'Title', 'Body');

        Http::assertNothingSent();
    }

    public function test_a_client_decision_reaches_the_channel_with_a_link(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);
        $post = $this->approvedPost();

        app(PostNotificationService::class)->send(
            collect([$this->owner]),
            new PostApprovalDecided($post, ApprovalDecision::Approved),
        );

        Http::assertSent(function ($request) use ($post) {
            return $request->url() === self::WEBHOOK
                && str_contains($request['text'], 'Post approved')
                && str_contains($request['text'], $this->client->name)
                && str_contains($request['text'], route('posts.edit', $post));
        });
    }

    public function test_changes_requested_reaches_the_channel_too(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);

        app(PostNotificationService::class)->send(
            collect([$this->owner]),
            new PostApprovalDecided($this->approvedPost(), ApprovalDecision::ChangesRequested),
        );

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Changes requested'));
    }

    /**
     * A channel that reports everything gets muted, and then it reports nothing. Only the moments
     * somebody has to act on are announced.
     */
    public function test_an_everyday_notification_is_not_announced(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);
        $post = $this->approvedPost();
        $comment = PostComment::factory()->for($post)->create([
            'user_id' => $this->owner->id,
            'body' => 'Looks good',
        ]);

        app(PostNotificationService::class)->send(
            collect([$this->owner]),
            new PostCommentAdded($post, $comment, false),
        );

        Http::assertNothingSent();
    }

    public function test_one_message_per_notification_not_one_per_recipient(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);
        $others = User::factory()->count(3)->for($this->org, 'organization')->role(Role::Strategist)->create();

        app(PostNotificationService::class)->send(
            collect([$this->owner])->concat($others),
            new PostApprovalDecided($this->approvedPost(), ApprovalDecision::Approved),
        );

        // It is a shared channel, not an inbox.
        Http::assertSentCount(1);
    }

    /**
     * Delivery is best-effort by contract. A dead webhook must never break the thing that
     * triggered the message — a client's approval has already happened.
     */
    public function test_a_failing_webhook_does_not_break_the_caller(): void
    {
        Http::fake(['*' => Http::response('no_service', 404)]);
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);

        app(PostNotificationService::class)->send(
            collect([$this->owner]),
            new PostApprovalDecided($this->approvedPost(), ApprovalDecision::Approved),
        );

        $this->addToAssertionCount(1);
    }

    public function test_a_non_https_webhook_is_refused(): void
    {
        // A queue worker posts to whatever is stored, so plaintext must not be reachable even if
        // something slipped past the form's validation.
        $this->org->update(['chat_webhook_url' => 'http://hooks.slack.test/insecure']);

        $this->assertFalse(app(ChatNotifier::class)->isConfiguredFor($this->org));

        app(ChatNotifier::class)->notifyOrganization($this->org, 'Title', 'Body');
        Http::assertNothingSent();
    }

    // --- settings ----------------------------------------------------------------------------

    public function test_an_owner_saves_a_webhook_and_a_blank_submit_keeps_it(): void
    {
        $this->actingAs($this->owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'chat_webhook_url' => self::WEBHOOK,
        ])->assertRedirect(route('organization.edit'));

        $this->assertSame(self::WEBHOOK, $this->org->fresh()->chat_webhook_url);

        // Editing an unrelated setting must not silently turn the integration off.
        $this->actingAs($this->owner)->put(route('organization.update'), [
            'default_timezone' => 'Europe/London',
            'default_currency' => 'GBP',
            'chat_webhook_url' => '',
        ])->assertRedirect(route('organization.edit'));

        $this->assertSame(self::WEBHOOK, $this->org->fresh()->chat_webhook_url);
    }

    public function test_a_plaintext_webhook_is_rejected_by_validation(): void
    {
        $this->actingAs($this->owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'chat_webhook_url' => 'http://hooks.slack.test/insecure',
        ])->assertSessionHasErrors('chat_webhook_url');
    }

    public function test_the_owner_can_send_a_test_message_and_turn_it_off(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);

        $this->actingAs($this->owner)->post(route('organization.test-chat'))
            ->assertRedirect(route('organization.edit'));

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Alima is connected'));

        $this->actingAs($this->owner)->delete(route('organization.chat.destroy'))
            ->assertRedirect(route('organization.edit'));

        $this->assertNull($this->org->fresh()->chat_webhook_url);
    }

    public function test_testing_without_a_webhook_says_so_rather_than_pretending(): void
    {
        $this->actingAs($this->owner)->post(route('organization.test-chat'))
            ->assertRedirect(route('organization.edit'));

        Http::assertNothingSent();
    }

    public function test_a_non_owner_cannot_touch_the_chat_settings(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)->post(route('organization.test-chat'))->assertForbidden();
        $this->actingAs($viewer)->delete(route('organization.chat.destroy'))->assertForbidden();
    }

    public function test_the_stored_webhook_is_never_exposed_to_the_page(): void
    {
        $this->org->update(['chat_webhook_url' => self::WEBHOOK]);

        $response = $this->actingAs($this->owner)->get(route('organization.edit'))->assertOk();

        $this->assertStringNotContainsString(self::WEBHOOK, $response->getContent());
        $response->assertInertia(fn ($page) => $page->where('organization.has_chat_webhook_url', true));
    }
}
