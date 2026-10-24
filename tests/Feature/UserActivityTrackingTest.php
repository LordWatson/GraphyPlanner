<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-8.2 — `TrackUserActivity` stamps `users.last_active_at` (the partner WAU signal) for real
 * org members, and leaves the org-less super admin alone.
 */
class UserActivityTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_org_members_activity_is_stamped(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->role(Role::Owner)->create(['last_active_at' => null]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertNotNull($user->fresh()->last_active_at);
    }

    public function test_a_super_admin_is_not_stamped(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['last_active_at' => null]);

        $this->actingAs($superAdmin)->get(route('super-admin.dashboard'))->assertOk();

        $this->assertNull($superAdmin->fresh()->last_active_at);
    }
}
