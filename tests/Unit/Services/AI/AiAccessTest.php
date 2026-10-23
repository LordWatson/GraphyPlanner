<?php

namespace Tests\Unit\Services\AI;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\AiAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Step M-7.1 — the single AI capability gate. AI is on for an org only when the platform flag AND
 * the org's own toggle are both on; using it is additionally Owner/Strategist-only.
 */
class AiAccessTest extends TestCase
{
    use RefreshDatabase;

    private AiAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = new AiAccess;
    }

    public function test_enabled_requires_both_the_platform_flag_and_the_org_toggle(): void
    {
        Config::set('ai.enabled', true);
        $on = Organization::factory()->create(['ai_enabled' => true]);
        $off = Organization::factory()->create(['ai_enabled' => false]);

        $this->assertTrue($this->access->enabledForOrg($on));
        $this->assertFalse($this->access->enabledForOrg($off));

        Config::set('ai.enabled', false);
        $this->assertFalse($this->access->enabledForOrg($on));
    }

    public function test_enabled_for_a_null_org_is_false(): void
    {
        Config::set('ai.enabled', true);

        $this->assertFalse($this->access->enabledForOrg(null));
        $this->assertFalse($this->access->enabledForUser(null));
        $this->assertFalse($this->access->canUse(null));
    }

    public function test_can_use_is_owner_and_strategist_only(): void
    {
        Config::set('ai.enabled', true);
        $org = Organization::factory()->create(['ai_enabled' => true]);

        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        $this->assertTrue($this->access->canUse($owner));
        $this->assertTrue($this->access->canUse($strategist));
        $this->assertFalse($this->access->canUse($designer));
        // ...but a Designer still counts as "enabled for" (the org has AI), gating read-only surfaces.
        $this->assertTrue($this->access->enabledForUser($designer));
    }

    public function test_authorize_aborts_404_when_disabled_and_403_for_the_wrong_role(): void
    {
        Config::set('ai.enabled', true);
        $org = Organization::factory()->create(['ai_enabled' => true]);
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        // Wrong role, AI enabled → 403.
        try {
            $this->access->authorize($designer);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // AI off for the org → 404 even for an Owner.
        $offOrg = Organization::factory()->create(['ai_enabled' => false]);
        $owner = User::factory()->for($offOrg, 'organization')->role(Role::Owner)->create();

        try {
            $this->access->authorize($owner);
            $this->fail('Expected a 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
