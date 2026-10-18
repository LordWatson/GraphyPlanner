<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step I-2.6 — what every Inertia response shares, and what it costs.
 */
class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_user_is_an_explicit_whitelist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.user', fn ($shared) => array_keys(collect($shared)->all()) === [
                    'id', 'name', 'email', 'avatar', 'role', 'org_id', 'client_id', 'email_verified_at', 'two_factor_enabled',
                ])
                ->where('auth.user.id', $user->id)
                ->where('auth.user.role', 'owner')
                ->missing('auth.user.password')
                ->missing('auth.user.created_at')
            );
    }

    public function test_shared_props_cost_at_most_two_queries_for_staff(): void
    {
        $user = User::factory()->create();

        $this->assertLessThanOrEqual(2, $this->sharedPropQueries($user));
        $this->assertLessThanOrEqual(1, $this->sharedPropQueries($user), 'The unread count should come from cache on the next request.');
    }

    public function test_shared_props_cost_at_most_two_queries_for_portal_contacts_once_warm(): void
    {
        $client = Client::factory()->create();
        $reviewer = User::factory()->role(Role::ClientReviewer)->create(['org_id' => $client->org_id, 'client_id' => $client->id]);

        $this->sharedPropQueries($reviewer);

        $this->assertLessThanOrEqual(2, $this->sharedPropQueries($reviewer));
    }

    public function test_the_unread_badge_updates_when_a_notification_is_sent_and_read(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $post = Post::factory()->for($client)->create(['org_id' => $user->org_id]);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('unreadNotificationsCount', 0));

        $user->notify(new PostPublishFailed($post));

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('unreadNotificationsCount', 1));

        $this->actingAs($user)->post(route('notifications.read-all'));

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('unreadNotificationsCount', 0));
    }

    public function test_the_portal_approvals_badge_updates_when_a_post_changes_status(): void
    {
        $client = Client::factory()->create();
        $reviewer = User::factory()->role(Role::ClientReviewer)->create(['org_id' => $client->org_id, 'client_id' => $client->id]);
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Draft]);

        $this->actingAs($reviewer)->get(route('portal.dashboard'))->assertInertia(fn ($page) => $page->where('portal.pendingApprovals', 0));

        $post->update(['status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->get(route('portal.dashboard'))->assertInertia(fn ($page) => $page->where('portal.pendingApprovals', 1));

        $post->delete();

        $this->actingAs($reviewer)->get(route('portal.dashboard'))->assertInertia(fn ($page) => $page->where('portal.pendingApprovals', 0));
    }

    /**
     * Resolve every shared prop for the user and count the queries it took.
     */
    private function sharedPropQueries(User $user): int
    {
        $user = $user->fresh();
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => $user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolve = function (mixed $value) use (&$resolve): mixed {
            $value = $value instanceof \Closure ? $value() : $value;

            return is_array($value) ? array_map($resolve, $value) : $value;
        };

        $resolve(app(HandleInertiaRequests::class)->share($request));

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
