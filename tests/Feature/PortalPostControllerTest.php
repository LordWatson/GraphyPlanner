<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6.5 — the client portal's posts list and approval flow: the authenticated counterpart to
 * the token-based `/review/:token` link, reusing `TransitionPostStatusAction`/
 * `CreatePostCommentAction` under the same `PostPolicy` rules.
 */
class PortalPostControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewer(Client $client): User
    {
        return User::factory()
            ->for($client->organization, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);
    }

    public function test_a_client_reviewer_sees_every_waiting_client_post_for_their_brand_on_the_list(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);

        $waiting = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        $draft = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($reviewer)->get(route('portal.posts.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/posts/index')
            ->has('posts', 1)
            ->where('posts.0.id', $waiting->id)
        );
    }

    public function test_a_client_reviewer_cannot_see_another_clients_posts_on_the_list(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);

        Post::factory()->for($clientB)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $response = $this->actingAs($reviewer)->get(route('portal.posts.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/posts/index')
            ->has('posts', 0)
        );
    }

    public function test_a_client_reviewer_can_approve_their_own_waiting_client_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->post(route('portal.posts.decide', $post), [
            'decision' => 'approved',
            'comment' => 'Looks great!',
        ])->assertRedirect(route('portal.posts.show', $post));

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertSame($reviewer->id, $post->approvals()->first()->user_id);
        $this->assertSame('Looks great!', $post->approvals()->first()->comment);
    }

    public function test_a_client_reviewer_can_request_changes_on_their_own_waiting_client_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->post(route('portal.posts.decide', $post), [
            'decision' => 'changes_requested',
            'comment' => 'Please swap the image.',
        ])->assertRedirect(route('portal.posts.show', $post));

        $post->refresh();
        $this->assertSame(PostStatus::ChangesRequested, $post->status);
    }

    public function test_a_client_reviewer_cannot_decide_on_another_clients_post(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);

        $otherPost = Post::factory()->for($clientB)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->post(route('portal.posts.decide', $otherPost), [
            'decision' => 'approved',
        ])->assertNotFound();

        $this->assertSame(PostStatus::WaitingClient, $otherPost->fresh()->status);
    }

    public function test_a_client_reviewer_cannot_decide_on_a_post_that_is_not_waiting_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Approved]);

        $this->actingAs($reviewer)->post(route('portal.posts.decide', $post), [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_a_client_reviewer_can_comment_on_their_own_post_and_it_is_never_internal_only(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->post(route('portal.posts.comments.store', $post), [
            'body' => 'When will this go live?',
            'internal_only' => true,
        ])->assertRedirect(route('portal.posts.show', $post));

        $comment = $post->comments()->latest('id')->first();
        $this->assertSame('When will this go live?', $comment->body);
        $this->assertFalse($comment->internal_only);
    }

    public function test_the_portal_post_detail_page_only_shows_non_internal_comments(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        PostComment::factory()->for($post)->create(['body' => 'Visible to client', 'internal_only' => false]);
        PostComment::factory()->for($post)->create(['body' => 'Internal-only note', 'internal_only' => true]);

        $response = $this->actingAs($reviewer)->get(route('portal.posts.show', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/posts/show')
            ->where('post.comments.0.body', 'Visible to client')
            ->missing('post.comments.1')
        );
    }
}
