<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_client_reviewers_are_sent_to_the_portal_instead_of_the_home_page()
    {
        $user = User::factory()->role(Role::ClientReviewer)->create();
        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));
        $response->assertRedirect(route('portal.dashboard'));
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
            ->has('items.failedPublishes.items', 1)
            ->where('items.failedPublishes.items.0.post_id', $post->id)
        );
    }

    public function test_home_lists_posts_waiting_on_internal_review()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::InternalReview,
        ]);

        $otherOrgUser = User::factory()->create();
        $otherClient = Client::factory()->create(['org_id' => $otherOrgUser->org_id]);
        Post::factory()->for($otherClient)->create([
            'org_id' => $otherOrgUser->org_id,
            'status' => PostStatus::InternalReview,
        ]);

        Post::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.internalReviews.items', 1)
            ->where('items.internalReviews.items.0.post_id', $post->id)
            ->where('items.internalReviews.items.0.reason', 'Waiting on internal review')
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
            ->has('items.waitingApprovals.items', 1)
            ->where('items.waitingApprovals.items.0.post_id', $post->id)
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
            ->has('items.missingMedia.items', 1)
            ->where('items.missingMedia.items.0.post_id', $post->id)
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
            ->has('items.disconnectedAccounts.items', 1)
            ->where('items.disconnectedAccounts.items.0.account_id', $account->id)
        );
    }

    public function test_client_reviewer_scoping_does_not_apply_since_reviewers_never_reach_the_page()
    {
        // Sanity check: even if a reviewer's client has attention items, they never reach the
        // page (redirected to the portal), and the policy still forbids it as defence in depth.
        $reviewer = User::factory()->role(Role::ClientReviewer)->create();
        $client = Client::factory()->create(['org_id' => $reviewer->org_id]);
        $reviewer->forceFill(['client_id' => $client->id])->save();

        Post::factory()->for($client)->create([
            'org_id' => $reviewer->org_id,
            'status' => PostStatus::Failed,
        ]);

        $this->actingAs($reviewer);

        $response = $this->get(route('needs-attention'));
        $response->assertRedirect(route('portal.dashboard'));
        $this->assertFalse($reviewer->can('viewHome', Post::class));
    }

    public function test_missing_media_follows_the_checklist_text_only_rule(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $linkedIn = SocialAccount::factory()->for($client)->create(['org_id' => $user->org_id, 'platform' => Platform::LinkedIn]);
        $instagram = SocialAccount::factory()->for($client)->create(['org_id' => $user->org_id, 'platform' => Platform::Instagram]);

        $textOnly = Post::factory()->for($client)->create(['org_id' => $user->org_id, 'status' => PostStatus::Draft]);
        PostTarget::factory()->create(['post_id' => $textOnly->id, 'social_account_id' => $linkedIn->id]);

        $mixed = Post::factory()->for($client)->create(['org_id' => $user->org_id, 'status' => PostStatus::Draft]);
        PostTarget::factory()->create(['post_id' => $mixed->id, 'social_account_id' => $linkedIn->id]);
        PostTarget::factory()->create(['post_id' => $mixed->id, 'social_account_id' => $instagram->id]);

        $withMedia = Post::factory()->for($client)->create(['org_id' => $user->org_id, 'status' => PostStatus::Draft]);
        PostTarget::factory()->create(['post_id' => $withMedia->id, 'social_account_id' => $instagram->id]);
        $withMedia->assets()->attach(Asset::factory()->for($client)->create(['org_id' => $user->org_id]));

        $this->actingAs($user)
            ->get(route('needs-attention'))
            ->assertInertia(fn ($page) => $page
                ->where('items.missingMedia.total', 1)
                ->where('items.missingMedia.items.0.post_id', $mixed->id)
            );
    }

    public function test_each_bucket_is_capped_but_reports_its_full_total(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        Post::factory()->for($client)->count(23)->create(['org_id' => $user->org_id, 'status' => PostStatus::Failed]);
        Post::factory()->for($client)->count(2)->create(['org_id' => $user->org_id, 'status' => PostStatus::PartiallyPublished]);

        $this->actingAs($user)
            ->get(route('needs-attention'))
            ->assertInertia(fn ($page) => $page
                ->has('items.failedPublishes.items', 20)
                ->where('items.failedPublishes.total', 25)
            );
    }

    public function test_home_runs_a_fixed_number_of_queries_regardless_of_post_count(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $user->org_id, 'platform' => Platform::Instagram]);

        $seed = function (int $perStatus) use ($user, $client, $account): void {
            foreach ([PostStatus::Failed, PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::Draft, PostStatus::Published] as $status) {
                Post::factory()->for($client)->count($perStatus)->create(['org_id' => $user->org_id, 'status' => $status])
                    ->each(fn (Post $post) => PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]));
            }
        };

        $queriesFor = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('needs-attention'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $seed(2);
        $queriesFor(); // warm the acting user's relations
        $small = $queriesFor();

        $seed(30);
        $large = $queriesFor();

        $this->assertSame($small, $large);
    }
}
