<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SendNotificationDigestAction;
use App\Contracts\ChatNotifier;
use App\Enums\NotificationCategory;
use App\Enums\Role;
use App\Mail\NotificationDigestMail;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use App\Notifications\PostCommentAdded;
use App\Services\Notifications\NullChatNotifier;
use App\Services\PostNotificationService;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step M-6.7 (I-12.1) — per-user notification preferences, the daily digest, the paginated centre,
 * and the swappable chat notifier (WhatsApp later; no Slack).
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function dbNotification(User $user, string $type, array $data = ['title' => 'Update', 'body' => 'x']): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data,
        ]);
    }

    public function test_defaults_show_everything_in_app_and_most_in_digest(): void
    {
        $matrix = NotificationPreferences::matrix($this->owner);
        $byCategory = collect($matrix)->keyBy('category');

        $this->assertTrue($byCategory['approvals']['in_app']);
        $this->assertTrue($byCategory['approvals']['digest']);
        // Comments are chatty — out of the digest by default.
        $this->assertFalse($byCategory['comments']['digest']);
        // "Other" can never be muted in-app.
        $this->assertTrue($byCategory['other']['in_app_locked']);
    }

    public function test_a_user_can_save_their_preferences(): void
    {
        $this->actingAs($this->owner)
            ->put(route('notifications.update'), [
                'preferences' => [
                    'approvals' => ['in_app' => false, 'digest' => true],
                    'comments' => ['in_app' => true, 'digest' => true],
                ],
            ])->assertRedirect();

        $this->owner->refresh();
        $this->assertFalse(NotificationPreferences::wants($this->owner, NotificationCategory::Approvals, 'in_app'));
        $this->assertTrue(NotificationPreferences::wants($this->owner, NotificationCategory::Comments, 'digest'));
    }

    public function test_muting_a_category_in_app_stops_the_notification(): void
    {
        $muted = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create([
            'notification_preferences' => ['approvals' => ['in_app' => false, 'digest' => true]],
        ]);
        $post = Post::factory()->for($this->org, 'organization')->create();

        app(PostNotificationService::class)->send(collect([$this->owner, $muted]), new ApprovalOverdue($post));

        $this->assertSame(1, $this->owner->fresh()->notifications()->count());
        $this->assertSame(0, $muted->fresh()->notifications()->count(), 'A muted category never reaches the bell.');
    }

    public function test_the_daily_digest_includes_opted_in_categories_only(): void
    {
        Mail::fake();

        // Approvals: digest on by default. Comments: digest off by default.
        $this->dbNotification($this->owner, ApprovalOverdue::class, ['title' => 'Overdue', 'body' => 'A post is overdue']);
        $this->dbNotification($this->owner, PostCommentAdded::class, ['title' => 'Comment', 'body' => 'A comment']);

        $count = app(SendNotificationDigestAction::class)();

        $this->assertSame(1, $count);
        Mail::assertQueued(NotificationDigestMail::class, fn (NotificationDigestMail $mail) => count($mail->items) === 1
            && $mail->items[0]['title'] === 'Overdue');
    }

    public function test_no_digest_when_a_user_muted_every_digest_category(): void
    {
        Mail::fake();
        $this->owner->update(['notification_preferences' => ['approvals' => ['in_app' => true, 'digest' => false]]]);
        $this->dbNotification($this->owner, ApprovalOverdue::class);

        app(SendNotificationDigestAction::class)();

        Mail::assertNothingQueued();
    }

    public function test_the_notification_centre_paginates_with_load_more_and_unread_filter(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->dbNotification($this->owner, ApprovalOverdue::class, ['title' => "N{$i}", 'body' => 'x']);
        }
        // Mark a few read so the unread filter has something to narrow.
        $this->owner->notifications()->limit(5)->get()->each->markAsRead();

        $first = $this->actingAs($this->owner)->getJson(route('notifications.index'))->assertOk()->json();
        $this->assertCount(20, $first['notifications']);
        $this->assertTrue($first['has_more']);
        $this->assertNotNull($first['next_before']);

        $second = $this->actingAs($this->owner)
            ->getJson(route('notifications.index', ['before' => $first['next_before']]))
            ->assertOk()->json();
        $this->assertCount(5, $second['notifications']);
        $this->assertFalse($second['has_more']);

        $unread = $this->actingAs($this->owner)
            ->getJson(route('notifications.index', ['filter' => 'unread']))
            ->assertOk()->json();
        $this->assertSame(20, $unread['unread_count']);
    }

    public function test_the_chat_notifier_is_the_null_implementation(): void
    {
        $this->assertInstanceOf(NullChatNotifier::class, app(ChatNotifier::class));
        $this->assertFalse(app(ChatNotifier::class)->isConfiguredFor($this->org));
    }
}
