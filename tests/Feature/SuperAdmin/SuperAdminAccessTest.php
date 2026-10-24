<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\SuperAdminReport;
use App\Models\User;
use Database\Seeders\SuperAdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 15.1 — the super admin guard, the org-workspace guard and the post-login redirect.
 */
class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exports / org settings sit behind `password.confirm` (Step M-1.2); not under test here.
        $this->confirmPassword();
    }

    /**
     * Every GET page in the `super-admin/*` group.
     *
     * @return list<string>
     */
    private function superAdminPages(Organization $organization, SuperAdminReport $report): array
    {
        return [
            route('super-admin.dashboard'),
            route('super-admin.organizations.index'),
            route('super-admin.organizations.create'),
            route('super-admin.organizations.show', $organization),
            route('super-admin.organizations.analytics', $organization),
            route('super-admin.analytics.index'),
            route('super-admin.partners.index'),
            route('super-admin.feedback.index'),
            route('super-admin.reports.index'),
            route('super-admin.reports.create'),
            route('super-admin.reports.show', $report),
            route('super-admin.reports.edit', $report),
            route('super-admin.reports.export', $report),
        ];
    }

    public function test_a_super_admin_can_reach_every_super_admin_page(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $organization = Organization::factory()->create();
        $report = SuperAdminReport::factory()->create(['created_by' => $superAdmin->id]);

        foreach ($this->superAdminPages($organization, $report) as $url) {
            $this->actingAs($superAdmin)->get($url)->assertOk();
        }
    }

    public function test_organization_roles_get_a_403_on_every_super_admin_page(): void
    {
        $organization = Organization::factory()->create();
        $report = SuperAdminReport::factory()->create();

        foreach ([Role::Owner, Role::Strategist, Role::Designer, Role::Viewer] as $role) {
            $user = User::factory()->for($organization, 'organization')->role($role)->create();

            foreach ($this->superAdminPages($organization, $report) as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_organization_roles_cannot_use_super_admin_write_endpoints(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $report = SuperAdminReport::factory()->create();

        $this->actingAs($owner)->post(route('super-admin.organizations.suspend', $organization))->assertForbidden();
        $this->actingAs($owner)->delete(route('super-admin.organizations.destroy', $organization), ['confirm_name' => $organization->name])->assertForbidden();
        $this->actingAs($owner)->delete(route('super-admin.reports.destroy', $report))->assertForbidden();

        $this->assertNull($organization->fresh()->suspended_at);
        $this->assertModelExists($report);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('super-admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_logging_in_as_a_super_admin_lands_on_the_console(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->post(route('login.store'), [
            'email' => $superAdmin->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($superAdmin);
        $response->assertRedirect('/super-admin');
    }

    public function test_logging_in_as_an_owner_still_lands_on_the_studio(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_the_seeded_super_admin_can_log_in_and_reach_the_console(): void
    {
        $this->seed(SuperAdminUserSeeder::class);

        $this->post(route('login.store'), [
            'email' => 'alexander.watson.dev@gmail.com',
            'password' => 'password',
        ])->assertRedirect('/super-admin');

        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('super-admin/dashboard'));
    }

    public function test_a_super_admin_is_sent_from_the_org_workspace_to_the_console(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (['dashboard', 'calendar', 'needs-attention', 'clients.index', 'staff.index', 'organization.edit', 'ai-usage.edit', 'portal.dashboard'] as $name) {
            $this->actingAs($superAdmin)->get(route($name))->assertRedirect(route('super-admin.dashboard'));
        }
    }

    public function test_a_super_admin_keeps_their_own_account_settings(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('profile.edit'))->assertOk();
        $this->actingAs($superAdmin)->get(route('appearance.edit'))->assertOk();
        $this->actingAs($superAdmin)->get(route('notifications.index'))->assertOk();
    }

    public function test_an_org_less_non_super_admin_account_is_refused_the_workspace(): void
    {
        $orphan = User::factory()->create(['org_id' => null, 'role' => null]);

        $this->actingAs($orphan)->get(route('dashboard'))->assertForbidden();
    }
}
