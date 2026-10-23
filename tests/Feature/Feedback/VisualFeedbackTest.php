<?php

namespace Tests\Feature\Feedback;

use App\Actions\Posts\Approvals\SubmitClientDecisionAction;
use App\Actions\Posts\Feedback\AcceptSuggestedEditAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalMode;
use App\Enums\ApprovalPolicy;
use App\Enums\CommentAnchorType;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Support\Posts\PostFeedbackPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Step M-6.4 (I-8.3) — visual feedback: pins on media, caption-range notes, suggested edits, threads
 * with resolve, and unresolved feedback blocking approval.
 */
class VisualFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    private User $reviewer;

    private Post $post;

    private Asset $image;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => null]);
        $this->reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $this->post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient, 'master_caption' => 'Fresh for spring']);
        $this->image = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);
    }

    public function test_a_reviewer_pins_a_comment_to_an_image(): void
    {
        $this->actingAs($this->reviewer)
            ->post(route('portal.posts.comments.store', $this->post), [
                'body' => 'Crop a little tighter here',
                'anchor_type' => 'image',
                'anchor' => ['asset_id' => $this->image->id, 'x' => 0.4, 'y' => 0.6],
            ])
            ->assertRedirect(route('portal.posts.show', $this->post));

        $comment = $this->post->comments()->sole();
        $this->assertSame(CommentAnchorType::Image, $comment->anchor_type);
        $this->assertEqualsWithDelta(0.4, $comment->anchor['x'], 0.001);
        $this->assertTrue($comment->isFeedback());
    }

    public function test_a_reviewer_pins_a_comment_to_a_caption_range_with_a_suggested_edit(): void
    {
        $this->actingAs($this->reviewer)
            ->post(route('portal.posts.comments.store', $this->post), [
                'body' => 'Can we rephrase this?',
                'anchor_type' => 'caption',
                'anchor' => ['start' => 0, 'end' => 5, 'quote' => 'Fresh'],
                'suggested_edit' => 'Brand new for spring',
            ])
            ->assertRedirect();

        $comment = $this->post->comments()->sole();
        $this->assertSame('Brand new for spring', $comment->suggested_edit);
        $this->assertSame(0, $comment->anchor['start']);
    }

    public function test_an_image_pin_must_reference_the_clients_own_asset(): void
    {
        $otherAsset = Asset::factory()->for(Client::factory()->for($this->org, 'organization'))->create(['org_id' => $this->org->id]);

        $this->actingAs($this->reviewer)
            ->from(route('portal.posts.show', $this->post))
            ->post(route('portal.posts.comments.store', $this->post), [
                'body' => 'x',
                'anchor_type' => 'image',
                'anchor' => ['asset_id' => $otherAsset->id, 'x' => 0.1, 'y' => 0.1],
            ])
            ->assertSessionHasErrors('anchor.asset_id');
    }

    public function test_replies_thread_under_a_root_and_are_never_pinned(): void
    {
        $root = PostComment::factory()->create(['post_id' => $this->post->id, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.2, 'y' => 0.2]]);

        $this->actingAs($this->owner)
            ->post(route('posts.comments.store', $this->post), [
                'body' => 'Fixed it',
                'parent_id' => $root->id,
                'anchor_type' => 'image',
                'anchor' => ['asset_id' => $this->image->id, 'x' => 0.9, 'y' => 0.9],
            ])
            ->assertRedirect(route('posts.edit', $this->post));

        $reply = PostComment::where('parent_id', $root->id)->sole();
        $this->assertNull($reply->anchor_type, 'A reply never carries an anchor.');
    }

    public function test_unresolved_feedback_blocks_approval_until_resolved(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::Any]);
        $pin = PostComment::factory()->create(['post_id' => $this->post->id, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.5, 'y' => 0.5]]);

        $this->assertTrue($this->post->hasUnresolvedFeedback());

        try {
            app(SubmitClientDecisionAction::class)($this->post, $this->reviewer, ApprovalDecision::Approved);
            $this->fail('Approval should be blocked by unresolved feedback.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to', $e->errors());
        }
        $this->assertSame(PostStatus::WaitingClient, $this->post->refresh()->status);

        // Resolve it, then approval goes through.
        $pin->update(['resolved_at' => now(), 'resolved_by_user_id' => $this->owner->id]);
        app(SubmitClientDecisionAction::class)($this->post, $this->reviewer, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::Approved, $this->post->refresh()->status);
    }

    public function test_owner_can_override_unresolved_feedback(): void
    {
        // The Owner override (internal_review → approved, skipping the client) also bypasses the
        // unresolved-feedback gate.
        $this->post->update(['status' => PostStatus::InternalReview, 'approval_mode' => ApprovalMode::ClientRequired]);
        PostComment::factory()->create(['post_id' => $this->post->id, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.5, 'y' => 0.5]]);

        app(TransitionPostStatusAction::class)->overrideApproval($this->post, $this->owner, 'Client approved verbally');

        $this->assertSame(PostStatus::Approved, $this->post->refresh()->status);
    }

    public function test_staff_resolves_and_reopens_a_thread(): void
    {
        $pin = PostComment::factory()->create(['post_id' => $this->post->id, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.5, 'y' => 0.5]]);

        $this->actingAs($this->owner)->post(route('comments.resolve', $pin))->assertRedirect(route('posts.edit', $this->post));
        $this->assertTrue($pin->refresh()->isResolved());

        $this->actingAs($this->owner)->post(route('comments.resolve', $pin), ['resolved' => false])->assertRedirect();
        $this->assertFalse($pin->refresh()->isResolved());
    }

    public function test_staff_accepts_a_suggested_caption_edit(): void
    {
        $suggestion = PostComment::factory()->create([
            'post_id' => $this->post->id,
            'anchor_type' => CommentAnchorType::Caption,
            'anchor' => ['start' => 0, 'end' => 5, 'quote' => 'Fresh'],
            'suggested_edit' => 'Brand new for spring',
        ]);

        $this->actingAs($this->owner)->post(route('comments.accept-suggestion', $suggestion))->assertRedirect(route('posts.edit', $this->post));

        $this->assertSame('Brand new for spring', $this->post->refresh()->master_caption);
        $suggestion->refresh();
        $this->assertTrue($suggestion->isResolved());
        $this->assertNotNull($suggestion->suggestion_resolved_at);
    }

    public function test_dismissing_a_suggestion_leaves_the_caption_and_resolves_the_thread(): void
    {
        $suggestion = PostComment::factory()->create([
            'post_id' => $this->post->id,
            'suggested_edit' => 'Nope',
        ]);

        app(AcceptSuggestedEditAction::class)->dismiss($suggestion, $this->owner);

        $this->assertSame('Fresh for spring', $this->post->refresh()->master_caption);
        $this->assertTrue($suggestion->refresh()->isResolved());
    }

    public function test_internal_feedback_is_hidden_from_the_client_view(): void
    {
        PostComment::factory()->create(['post_id' => $this->post->id, 'internal_only' => true, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.1, 'y' => 0.1]]);
        PostComment::factory()->create(['post_id' => $this->post->id, 'internal_only' => false, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.2, 'y' => 0.2]]);

        $clientThreads = PostFeedbackPresenter::threads($this->post, $this->reviewer, forClient: true);
        $staffThreads = PostFeedbackPresenter::threads($this->post->refresh(), $this->owner, forClient: false);

        $this->assertCount(1, $clientThreads);
        $this->assertCount(2, $staffThreads);
    }

    public function test_a_reviewer_resolves_feedback_from_the_portal(): void
    {
        $pin = PostComment::factory()->create(['post_id' => $this->post->id, 'anchor_type' => CommentAnchorType::Image, 'anchor' => ['asset_id' => $this->image->id, 'x' => 0.5, 'y' => 0.5]]);

        $this->actingAs($this->reviewer)->post(route('portal.comments.resolve', $pin))->assertRedirect(route('portal.posts.show', $this->post));
        $this->assertTrue($pin->refresh()->isResolved());
    }
}
