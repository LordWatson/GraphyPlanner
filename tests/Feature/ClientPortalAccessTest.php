<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6.3 — the client portal shell/middleware: a `Role::ClientReviewer` may reach `/portal/*`
 * and see their own client's data, but guessing another client's post/invoice id 404s, and every
 * other role is rejected outright.
 */
class ClientPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_reviewer_can_view_the_portal_dashboard(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        $response = $this->actingAs($reviewer)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/dashboard')
            ->where('client.name', $client->name)
        );
    }

    /**
     * The shared `portal.pendingApprovals` prop drives the portal nav's "Approvals" badge: only
     * this contact's own `waiting_client` posts count, and staff never receive the key's payload.
     */
    public function test_portal_pending_approvals_count_is_shared_for_client_reviewers_only(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        Post::factory()->for($client)->count(2)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Approved]);
        Post::factory()->for($otherClient)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)
            ->get(route('portal.posts.index'))
            ->assertInertia(fn ($page) => $page->where('portal.pendingApprovals', 2));

        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('portal', null));
    }

    public function test_non_client_reviewer_roles_are_rejected_from_the_portal(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('portal.dashboard'))->assertForbidden();
    }

    public function test_client_reviewer_can_view_their_own_clients_post_and_invoice(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->get(route('portal.posts.show', $post))->assertOk();
        $this->actingAs($reviewer)->get(route('portal.invoices.show', $invoice))->assertOk();
    }

    public function test_client_reviewer_cannot_view_another_clients_post_by_guessing_its_id(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $clientA->id]);

        $otherPost = Post::factory()->for($clientB)->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->get(route('portal.posts.show', $otherPost))->assertNotFound();
    }

    public function test_client_reviewer_cannot_view_another_clients_invoice_by_guessing_its_id(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $clientA->id]);

        $otherInvoice = Invoice::factory()->for($clientB)->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->get(route('portal.invoices.show', $otherInvoice))->assertNotFound();
    }

    /**
     * Pre-review internal stages never reach the portal: their detail page, decision and comment
     * endpoints all 404, exactly like another client's post.
     */
    public function test_client_reviewer_cannot_open_or_comment_on_an_internal_draft(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        foreach ([PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview] as $status) {
            $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $status]);

            $this->actingAs($reviewer)->get(route('portal.posts.show', $post))->assertNotFound();
            $this->actingAs($reviewer)
                ->post(route('portal.posts.comments.store', $post), ['body' => 'Peeking'])
                ->assertNotFound();
        }

        $this->assertDatabaseMissing('post_comments', ['body' => 'Peeking']);
    }

    public function test_an_internal_status_filter_on_the_post_list_is_ignored(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);
        $waiting = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)
            ->get(route('portal.posts.index', ['status' => 'draft']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts', 1)
                ->where('posts.0.id', $waiting->id)
                ->where('filters.status', null)
            );
    }

    public function test_guests_are_redirected_to_login_from_the_portal(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));
    }
}
