<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §12 acceptance test: two targets on accounts in different timezones (London/Dubai) must
     * keep independent local times and independent UTC instants — never collapsed onto one.
     */
    public function test_targets_on_different_timezone_accounts_keep_independent_local_times(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $london = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Facebook,
            'timezone' => 'Europe/London',
        ]);

        $dubai = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'timezone' => 'Asia/Dubai',
        ]);

        $response = $this->actingAs($owner)->post(route('clients.posts.store', $client), [
            'master_caption' => 'Launch day',
            'targets' => [
                [
                    'social_account_id' => $london->id,
                    'scheduled_local_date' => '2026-10-01',
                    'scheduled_local_time' => '09:00',
                ],
                [
                    'social_account_id' => $dubai->id,
                    'scheduled_local_date' => '2026-10-01',
                    'scheduled_local_time' => '09:00',
                ],
            ],
        ]);

        $response->assertRedirect(route('clients.show', $client));

        $post = Post::firstOrFail();
        $londonTarget = $post->targets()->where('social_account_id', $london->id)->firstOrFail();
        $dubaiTarget = $post->targets()->where('social_account_id', $dubai->id)->firstOrFail();

        // Independent local times, both stored as the requested 09:00.
        $this->assertSame('2026-10-01', $londonTarget->scheduled_local_date->toDateString());
        $this->assertSame('09:00', $londonTarget->scheduled_local_time);
        $this->assertSame('2026-10-01', $dubaiTarget->scheduled_local_date->toDateString());
        $this->assertSame('09:00', $dubaiTarget->scheduled_local_time);

        // Independent UTC instants derived from each account's own timezone — never collapsed.
        $this->assertSame('2026-10-01T08:00:00+00:00', $londonTarget->scheduled_at_utc->toIso8601String());
        $this->assertSame('2026-10-01T05:00:00+00:00', $dubaiTarget->scheduled_at_utc->toIso8601String());
        $this->assertNotEquals(
            $londonTarget->scheduled_at_utc->toIso8601String(),
            $dubaiTarget->scheduled_at_utc->toIso8601String(),
        );
    }

    public function test_designer_cannot_create_a_post_outside_their_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherDesigner = User::factory()->for($otherOrg, 'organization')->role(Role::Designer)->create();

        $response = $this->actingAs($otherDesigner)->post(route('clients.posts.store', $client), [
            'master_caption' => 'Nope',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('posts', 0);
    }

    /**
     * The client show page (frontend) must expose the posts created for that client, along
     * with the social accounts available as post targets, so the module is reachable from the UI.
     */
    public function test_client_show_page_exposes_posts_and_target_accounts(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'timezone' => 'Europe/Amsterdam',
        ]);

        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
        ]);

        $response = $this->actingAs($owner)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('posts', 1)
            ->has('targetAccounts', 1)
            ->where('can.createPost', true)
            ->where('posts.0.id', $post->id)
            ->where('posts.0.targets.0.social_account_id', $account->id)
            ->where('targetAccounts.0.id', $account->id)
        );
    }
}
