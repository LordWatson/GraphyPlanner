<?php

namespace Tests\Unit\Plans;

use App\Actions\Posts\CreatePostAction;
use App\Actions\SocialAccounts\CreateSocialAccountAction;
use App\Actions\StaffInvitations\CreateStaffInvitationAction;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\Role;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-2.2 / B-9.1 — the plan limits are enforced inside the Actions (not just the contract). Plan
 * caps are overridden to small values here so the mechanism, not the exact numbers, is under test
 * (the numbers live in PlanDefinitionsTest).
 */
class EntitlementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.plans.business_free.limits.social_accounts' => 2,
            'plans.plans.business_free.limits.scheduled_posts_per_month' => 2,
            'plans.plans.business_free.limits.team_seats' => 1,
        ]);
    }

    private function freeBusiness(): Client
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);

        return Client::factory()->for($org, 'organization')->create();
    }

    public function test_social_account_limit_is_enforced_in_the_action(): void
    {
        $client = $this->freeBusiness();
        SocialAccount::factory()->count(2)->for($client)->create(['org_id' => $client->org_id]);

        $this->expectException(PlanLimitReachedException::class);

        app(CreateSocialAccountAction::class)($client, [
            'platform' => Platform::Instagram,
            'handle' => '@over',
            'timezone' => 'Europe/London',
        ]);
    }

    public function test_a_social_account_under_the_limit_is_allowed(): void
    {
        $client = $this->freeBusiness();
        SocialAccount::factory()->for($client)->create(['org_id' => $client->org_id]);

        $account = app(CreateSocialAccountAction::class)($client, [
            'platform' => Platform::Instagram,
            'handle' => '@ok',
            'timezone' => 'Europe/London',
        ]);

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
    }

    public function test_monthly_post_limit_is_enforced_in_the_action(): void
    {
        $client = $this->freeBusiness();
        Post::factory()->count(2)->create(['org_id' => $client->org_id, 'client_id' => $client->id]);

        $this->expectException(PlanLimitReachedException::class);

        app(CreatePostAction::class)($client, ['targets' => []]);
    }

    public function test_team_seat_limit_is_enforced_in_the_invitation_action(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        // The owner already fills the single seat.
        $this->expectException(PlanLimitReachedException::class);

        app(CreateStaffInvitationAction::class)($org, 'teammate@example.com', Role::Strategist, $owner);
    }

    public function test_agency_orgs_are_not_capped_by_the_actions(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => null]);
        $client = Client::factory()->for($org, 'organization')->create();
        SocialAccount::factory()->count(5)->for($client)->create(['org_id' => $org->id]);

        $account = app(CreateSocialAccountAction::class)($client, [
            'platform' => Platform::Instagram,
            'handle' => '@agency',
            'timezone' => 'Europe/London',
        ]);

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id]);
    }
}
