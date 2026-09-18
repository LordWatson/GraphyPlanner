<?php

namespace Tests\Feature;

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

        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
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

    public function test_guests_are_redirected_to_login_from_the_portal(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));
    }
}
