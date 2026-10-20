<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Mail\ClientReviewRequestedMail;
use App\Mail\OpsAlertMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostWaitingForInternalReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Step I-1.4 — transitions re-check the *locked* status, and every side effect (client email,
 * notifications, publish job) only leaves after commit.
 */
class TransitionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_conflicting_decisions_only_let_one_through(): void
    {
        Notification::fake();
        $post = $this->makePost(PostStatus::WaitingClient);

        // Two requests loaded the same `waiting_client` post (review link + portal)...
        $viaReviewLink = Post::findOrFail($post->id);
        $viaPortal = Post::findOrFail($post->id);
        $action = app(TransitionPostStatusAction::class);

        $action($viaReviewLink, PostStatus::Approved, null);

        try {
            $action($viaPortal, PostStatus::ChangesRequested, null);
            $this->fail('The second, conflicting decision should have been rejected.');
        } catch (InvalidPostTransitionException $e) {
            $this->assertSame(PostStatus::Approved, $e->from);
        }

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $this->assertSame(1, $post->approvals()->count());
        $this->assertSame(1, $post->activityLogs()->count());
    }

    public function test_a_conflicting_transition_renders_a_409_for_json_callers(): void
    {
        Route::post('_test/conflict', fn () => throw new InvalidPostTransitionException(1, PostStatus::Approved, PostStatus::ChangesRequested));

        $this->postJson('_test/conflict')
            ->assertStatus(409)
            ->assertJsonPath('message', 'This post changed while you were working on it — it is now approved.');
    }

    public function test_a_conflicting_transition_redirects_back_with_an_error_for_inertia_pages(): void
    {
        Route::middleware('web')->post('_test/conflict', fn () => throw new InvalidPostTransitionException(1, PostStatus::Approved, PostStatus::ChangesRequested));

        $this->from('/somewhere')->post('_test/conflict')
            ->assertRedirect('/somewhere')
            ->assertSessionHasErrors('to');
    }

    public function test_the_client_review_email_is_queued_not_sent_inline(): void
    {
        Mail::fake();
        $post = $this->makePost(PostStatus::InternalReview);

        app(TransitionPostStatusAction::class)($post, PostStatus::WaitingClient, null);

        Mail::assertQueued(ClientReviewRequestedMail::class);
        Mail::assertNotSent(ClientReviewRequestedMail::class);
    }

    public function test_nothing_is_emailed_or_notified_when_the_transaction_rolls_back(): void
    {
        Mail::fake();
        Notification::fake();
        $post = $this->makePost(PostStatus::InternalReview);
        User::factory()->for($post->organization, 'organization')->role(Role::Owner)->create();

        try {
            DB::transaction(function () use ($post) {
                app(TransitionPostStatusAction::class)($post, PostStatus::WaitingClient, null);

                throw new RuntimeException('Something later in the same request failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertSame(PostStatus::InternalReview, $post->fresh()->status);
    }

    public function test_notifications_are_sent_after_commit(): void
    {
        Notification::fake();
        $post = $this->makePost(PostStatus::Draft);
        $teammate = User::factory()->for($post->organization, 'organization')->role(Role::Strategist)->create();

        app(TransitionPostStatusAction::class)($post, PostStatus::InternalReview, null);

        Notification::assertSentTo($teammate, PostWaitingForInternalReview::class);
    }

    public function test_every_mailable_and_notification_is_queued(): void
    {
        foreach (glob(app_path('Mail/*.php')) ?: [] as $file) {
            $class = 'App\\Mail\\'.basename($file, '.php');

            // Step M-1.1: the on-call alert is sent inline on purpose — it may be about the queue.
            if ($class === OpsAlertMail::class) {
                continue;
            }

            $this->assertContains(ShouldQueue::class, class_implements($class), $class);
        }

        foreach (glob(app_path('Notifications/*.php')) ?: [] as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');
            $this->assertContains(ShouldQueue::class, class_implements($class), $class);
        }

        $this->assertTrue((bool) config('queue.connections.'.config('queue.default').'.after_commit'));
        $this->assertTrue(class_exists(PostApprovalDecided::class));
    }

    private function makePost(PostStatus $status): Post
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create(['approval_email' => 'client@example.com']);

        return Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $status]);
    }
}
