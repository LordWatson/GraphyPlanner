<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-2.6 / B-9.4 — super-admin plan overrides, temporary credit grants, per-org and global
 * billing switches. Each is audited, and non-super-admins can't reach any of it.
 */
class PlanControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
    }

    public function test_a_super_admin_overrides_an_orgs_plan_and_it_is_audited(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.plan', $this->org), ['plan' => 'business_pro', 'note' => 'comped'])
            ->assertRedirect();

        $this->assertSame('business_pro', $this->org->refresh()->plan);
        $this->assertDatabaseHas('plan_audit_logs', [
            'org_id' => $this->org->id,
            'action' => PlanAuditLog::ACTION_PLAN_CHANGED,
            'to_plan' => 'business_pro',
            'actor_user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_a_super_admin_grants_temporary_credits(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.credits', $this->org), ['credits' => 500, 'expires_in_days' => 30])
            ->assertRedirect();

        $this->org->refresh();
        $this->assertSame(500, $this->org->bonus_ai_text_credits);
        $this->assertNotNull($this->org->bonus_ai_credits_expires_at);
        $this->assertDatabaseHas('plan_audit_logs', [
            'org_id' => $this->org->id,
            'action' => PlanAuditLog::ACTION_CREDITS_GRANTED,
        ]);
    }

    public function test_a_super_admin_sets_a_per_org_billing_override(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.billing', $this->org), ['mode' => 'on'])
            ->assertRedirect();

        $this->assertTrue($this->org->refresh()->billing_enabled);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.billing', $this->org), ['mode' => 'inherit'])
            ->assertRedirect();

        $this->assertNull($this->org->refresh()->billing_enabled);
    }

    public function test_a_super_admin_toggles_the_global_billing_switch(): void
    {
        $this->assertFalse(app(PlatformSettings::class)->billingEnabled());

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.settings.billing'), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue(app(PlatformSettings::class)->billingEnabled());
    }

    public function test_the_platform_settings_page_renders(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.settings.edit'))
            ->assertOk();
    }

    public function test_a_normal_owner_cannot_reach_super_admin_plan_controls(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('super-admin.organizations.plan', $this->org), ['plan' => 'business_pro'])
            ->assertForbidden();

        $this->assertSame('business_free', $this->org->refresh()->plan);
    }
}
