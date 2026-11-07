<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Org-wide two-factor: the switch a security questionnaire asks for, which Fortify's per-user 2FA
 * cannot answer on its own.
 *
 * The tests pin the two ways this feature could do harm rather than good: letting an organisation
 * believe it is enforced when it is not, and locking out the very people who have to fix it.
 */
class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['require_two_factor' => true]);
    }

    private function member(Role $role = Role::Strategist, bool $withTwoFactor = false): User
    {
        $factory = User::factory()->for($this->org, 'organization')->role($role);

        return ($withTwoFactor ? $factory->withTwoFactor() : $factory)->create();
    }

    public function test_a_member_without_two_factor_is_sent_to_their_security_page(): void
    {
        $this->actingAs($this->member())->get(route('clients.index'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_a_member_with_two_factor_carries_on_as_normal(): void
    {
        $this->actingAs($this->member(withTwoFactor: true))->get(route('clients.index'))->assertOk();
    }

    public function test_nothing_changes_for_an_organization_that_has_not_turned_it_on(): void
    {
        $this->org->update(['require_two_factor' => false]);

        $this->actingAs($this->member())->get(route('clients.index'))->assertOk();
    }

    /**
     * Otherwise this redirects to a page it has itself blocked, and the member can never comply.
     */
    public function test_the_pages_needed_to_comply_stay_reachable(): void
    {
        $user = $this->member();

        $this->confirmPassword()->actingAs($user)->get(route('security.edit'))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_a_locked_out_member_can_still_log_out(): void
    {
        $this->actingAs($this->member())->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /**
     * A redirect is useless to a form post or an XHR, and silently bouncing one would read as a
     * bug rather than a policy.
     */
    public function test_a_write_is_refused_outright_rather_than_redirected(): void
    {
        $this->actingAs($this->member(Role::Owner))
            ->post(route('clients.store'), ['name' => 'Acme'])
            ->assertForbidden();
    }

    /**
     * Forcing an authenticator app on somebody else's client would lock them out of their own
     * approvals with nobody able to help. "All members" means the agency's team.
     */
    public function test_a_client_reviewer_in_the_portal_is_not_affected(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create();
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $this->actingAs($reviewer)->get(route('portal.dashboard'))->assertOk();
    }

    // --- turning it on -------------------------------------------------------------------------

    public function test_an_owner_with_two_factor_can_turn_it_on(): void
    {
        $this->org->update(['require_two_factor' => false]);
        $owner = $this->member(Role::Owner, withTwoFactor: true);

        $this->confirmPassword()->actingAs($owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'require_two_factor' => '1',
        ])->assertRedirect();

        $this->assertTrue($this->org->fresh()->require_two_factor);
    }

    /**
     * The footgun this feature would otherwise ship with: the owner turns it on, the middleware
     * locks them out of the page that holds the switch, and only a support ticket gets them back.
     */
    public function test_an_owner_without_two_factor_is_refused(): void
    {
        $this->org->update(['require_two_factor' => false]);
        $owner = $this->member(Role::Owner);

        $this->confirmPassword()->actingAs($owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'require_two_factor' => '1',
        ])->assertSessionHasErrors('require_two_factor');

        $this->assertFalse($this->org->fresh()->require_two_factor);
    }

    public function test_it_can_be_turned_back_off(): void
    {
        $owner = $this->member(Role::Owner, withTwoFactor: true);

        $this->confirmPassword()->actingAs($owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'require_two_factor' => '0',
        ])->assertRedirect();

        $this->assertFalse($this->org->fresh()->require_two_factor);
    }
}
