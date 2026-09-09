<?php

namespace Tests\Feature;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_transitioning_a_post_to_waiting_client_emails_the_clients_approval_address(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['approval_email' => 'client@example.com']);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::InternalReview]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::WaitingClient->value,
        ])->assertRedirect(route('posts.edit', $post));

        Mail::assertSent(ClientReviewRequestedMail::class, function (ClientReviewRequestedMail $mail) use ($post) {
            return $mail->hasTo('client@example.com') && $mail->post->id === $post->id;
        });

        $this->assertSame(1, $post->reviewTokens()->count());
    }

    public function test_no_email_is_sent_when_the_client_has_no_approval_email_on_file(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['approval_email' => null]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::InternalReview]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::WaitingClient->value,
        ])->assertRedirect(route('posts.edit', $post));

        Mail::assertNothingSent();
    }

    public function test_a_valid_token_can_view_and_approve_the_waiting_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        ['token' => $token] = (new CreateReviewTokenAction)($client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('review/show')
                ->where('post.id', $post->id)
                ->where('post.can_decide', true)
            );

        $this->post(route('review.decide', $token), [
            'decision' => 'approved',
            'comment' => 'Looks great!',
        ])->assertRedirect(route('review.show', $token));

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertNull($post->approvals()->first()->user_id);
        $this->assertSame('Looks great!', $post->approvals()->first()->comment);
    }

    public function test_the_review_message_and_only_non_internal_comments_are_visible_on_the_review_page(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
            'review_message' => 'Please check the caption tone before approving.',
        ]);
        PostComment::factory()->for($post)->create(['body' => 'Visible to client', 'internal_only' => false]);
        PostComment::factory()->for($post)->create(['body' => 'Internal-only note', 'internal_only' => true]);
        ['token' => $token] = (new CreateReviewTokenAction)($client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('review/show')
                ->where('post.review_message', 'Please check the caption tone before approving.')
                ->where('post.comments.0.body', 'Visible to client')
                ->missing('post.comments.1')
            );
    }

    public function test_an_invalid_token_is_not_found(): void
    {
        $this->get(route('review.show', 'not-a-real-token'))->assertNotFound();
    }

    public function test_a_revoked_token_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        ['token' => $token, 'model' => $reviewToken] = (new CreateReviewTokenAction)($client, $post);
        $reviewToken->update(['revoked_at' => now()]);

        $this->get(route('review.show', $token))->assertNotFound();
    }

    public function test_a_token_cannot_see_another_clients_post_or_any_billing_field(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create(['retainer_amount' => 5000]);
        $otherClient = Client::factory()->for($org, 'organization')->create(['retainer_amount' => 9999]);
        Post::factory()->for($otherClient)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        ['token' => $token] = (new CreateReviewTokenAction)($client, $post);

        $response = $this->get(route('review.show', $token))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('review/show')
            ->where('post.id', $post->id)
            ->missing('post.retainer_amount')
            ->missing('client.retainer_amount')
        );
    }
}
