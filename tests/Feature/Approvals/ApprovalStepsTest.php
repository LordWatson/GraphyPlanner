<?php

namespace Tests\Feature\Approvals;

use App\Actions\Posts\Approvals\GenerateApprovalCertificatePdfAction;
use App\Actions\Posts\Approvals\SubmitClientDecisionAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalMode;
use App\Enums\ApprovalPolicy;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Step M-6.3 (I-8.2) — multi-approver and staged approvals: any / all / sequential, the approval
 * certificate, and "approve all".
 */
class ApprovalStepsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create([
            'default_approval_mode' => ApprovalMode::ClientRequired,
            'approval_email' => null,
        ]);
    }

    private function approver(string $email, string $name): array
    {
        $contact = ClientContact::factory()->forClient($this->client)->create(['email' => $email, 'name' => $name, 'receives_review_emails' => true]);
        $user = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id, 'email' => $email]);

        return [$contact, $user];
    }

    /** Send a fresh post to the client for review (this builds the approval steps via the hook). */
    private function postWaitingOnClient(): Post
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::InternalReview]);

        return app(TransitionPostStatusAction::class)($post, PostStatus::WaitingClient, $this->owner);
    }

    public function test_any_policy_releases_on_the_first_approval(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::Any]);
        [, $alex] = $this->approver('alex@acme.test', 'Alex');
        $this->approver('sam@acme.test', 'Sam');

        $post = $this->postWaitingOnClient();
        $this->assertSame(2, $post->approvalSteps()->count());

        app(SubmitClientDecisionAction::class)($post, $alex, ApprovalDecision::Approved);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_all_policy_waits_for_every_approver(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::All]);
        [, $alex] = $this->approver('alex@acme.test', 'Alex');
        [, $sam] = $this->approver('sam@acme.test', 'Sam');

        $post = $this->postWaitingOnClient();

        app(SubmitClientDecisionAction::class)($post, $alex, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::WaitingClient, $post->refresh()->status, 'Still waiting on the second approver.');

        app(SubmitClientDecisionAction::class)($post, $sam, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_sequential_policy_enforces_the_order(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::Sequential]);
        [, $alex] = $this->approver('alex@acme.test', 'Alex'); // position 0
        [, $sam] = $this->approver('sam@acme.test', 'Sam');    // position 1

        $post = $this->postWaitingOnClient();

        // Sam can't go first.
        try {
            app(SubmitClientDecisionAction::class)($post, $sam, ApprovalDecision::Approved);
            $this->fail('Expected an out-of-turn rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decision', $e->errors());
        }
        $this->assertSame(PostStatus::WaitingClient, $post->refresh()->status);

        // Alex (first) approves — still waiting on Sam.
        app(SubmitClientDecisionAction::class)($post, $alex, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::WaitingClient, $post->refresh()->status);

        // Now it's Sam's turn; his approval releases it.
        app(SubmitClientDecisionAction::class)($post, $sam, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_any_approver_requesting_changes_stops_the_post(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::All]);
        $this->approver('alex@acme.test', 'Alex');
        [, $sam] = $this->approver('sam@acme.test', 'Sam');

        $post = $this->postWaitingOnClient();

        app(SubmitClientDecisionAction::class)($post, $sam, ApprovalDecision::ChangesRequested, 'Swap the image.');

        $this->assertSame(PostStatus::ChangesRequested, $post->refresh()->status);
    }

    public function test_a_non_approver_reviewer_cannot_approve(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::All]);
        $this->approver('alex@acme.test', 'Alex');
        $outsider = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id, 'email' => 'nope@acme.test']);

        $post = $this->postWaitingOnClient();

        $this->expectException(ValidationException::class);
        app(SubmitClientDecisionAction::class)($post, $outsider, ApprovalDecision::Approved);
    }

    public function test_a_client_with_no_named_approvers_keeps_the_single_generic_step(): void
    {
        // No contacts: the generic step is satisfied by any reviewer / the review link.
        $post = $this->postWaitingOnClient();
        $this->assertSame(1, $post->approvalSteps()->count());
        $this->assertNull($post->approvalSteps()->first()->client_contact_id);

        app(SubmitClientDecisionAction::class)($post, null, ApprovalDecision::Approved);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_approval_certificate_is_a_pdf_once_approved(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::All]);
        [, $alex] = $this->approver('alex@acme.test', 'Alex');
        [, $sam] = $this->approver('sam@acme.test', 'Sam');

        $post = $this->postWaitingOnClient();
        app(SubmitClientDecisionAction::class)($post, $alex, ApprovalDecision::Approved);
        app(SubmitClientDecisionAction::class)($post, $sam, ApprovalDecision::Approved);
        $post->refresh();

        // Each approver's step recorded the exact revision they approved.
        $this->assertNotNull($post->approvalSteps()->whereNotNull('approved_revision_id')->first());

        $pdf = app(GenerateApprovalCertificatePdfAction::class)($post);
        $this->assertStringStartsWith('%PDF', $pdf);

        // Portal route: the owning reviewer can download it; it 404s before approval.
        $this->actingAs($alex)->get(route('portal.posts.certificate', $post))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $draft = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);
        $this->actingAs($alex)->get(route('portal.posts.certificate', $draft))->assertNotFound();
    }

    public function test_approve_all_releases_every_post_waiting_on_the_reviewer(): void
    {
        $this->client->update(['approval_policy' => ApprovalPolicy::Any]);
        [, $alex] = $this->approver('alex@acme.test', 'Alex');

        $a = $this->postWaitingOnClient();
        $b = $this->postWaitingOnClient();

        $this->actingAs($alex)->post(route('portal.posts.decide-all'))->assertRedirect(route('portal.posts.index'));

        $this->assertSame(PostStatus::Approved, $a->refresh()->status);
        $this->assertSame(PostStatus::Approved, $b->refresh()->status);
    }

    public function test_review_link_token_still_approves_a_multi_approver_client(): void
    {
        // The anonymous token speaks for the client as a whole (documented), releasing the post.
        $this->client->update(['approval_policy' => ApprovalPolicy::All]);
        $this->approver('alex@acme.test', 'Alex');
        $this->approver('sam@acme.test', 'Sam');

        $post = $this->postWaitingOnClient();

        app(SubmitClientDecisionAction::class)($post, null, ApprovalDecision::Approved);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }
}
