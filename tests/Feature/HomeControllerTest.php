<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('needs-attention'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_home_page()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));
        $response->assertOk();
    }

    public function test_client_reviewers_cannot_view_the_home_page()
    {
        $user = User::factory()->role(Role::ClientReviewer)->create();
        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));
        $response->assertForbidden();
    }

    public function test_home_lists_failed_publishes()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::Failed,
        ]);

        // A failed post from another org must never show up.
        Post::factory()->create(['status' => PostStatus::Failed]);

        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.failedPublishes', 1)
            ->where('items.failedPublishes.0.post_id', $post->id)
        );
    }

    public function test_home_lists_posts_waiting_on_client_approval()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::WaitingClient,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.waitingApprovals', 1)
            ->where('items.waitingApprovals.0.post_id', $post->id)
        );
    }

    public function test_home_lists_posts_missing_required_media()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::Draft,
        ]);

        // Archived posts should never appear even without media.
        Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::Archived,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.missingMedia', 1)
            ->where('items.missingMedia.0.post_id', $post->id)
        );
    }

    public function test_home_lists_disconnected_social_accounts()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'connection_status' => ConnectionStatus::TokenExpired,
        ]);

        // A connected account must never show up.
        SocialAccount::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.disconnectedAccounts', 1)
            ->where('items.disconnectedAccounts.0.account_id', $account->id)
        );
    }

    public function test_client_reviewer_scoping_does_not_apply_since_reviewers_are_forbidden()
    {
        // Sanity check: even if a reviewer's client has attention items, they are forbidden
        // from the page entirely (see test_client_reviewers_cannot_view_the_home_page).
        $reviewer = User::factory()->role(Role::ClientReviewer)->create();
        $client = Client::factory()->create(['org_id' => $reviewer->org_id]);
        $reviewer->forceFill(['client_id' => $client->id])->save();

        Post::factory()->for($client)->create([
            'org_id' => $reviewer->org_id,
            'status' => PostStatus::Failed,
        ]);

        $this->actingAs($reviewer);

        $response = $this->get(route('needs-attention'));
        $response->assertForbidden();
    }
}
