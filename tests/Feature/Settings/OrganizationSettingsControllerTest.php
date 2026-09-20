<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('organization.edit'));
        $response->assertRedirect(route('login'));
    }

    public function test_owner_can_view_the_organization_settings_page()
    {
        $user = User::factory()->role(Role::Owner)->create();

        $response = $this->actingAs($user)->get(route('organization.edit'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/organization')
            ->where('organization.default_timezone', $user->organization->default_timezone)
            ->where('organization.default_currency', $user->organization->default_currency)
            ->where('organization.has_upload_post_key', false)
            ->where('organization.has_xai_key', false)
            ->has('currencies')
        );
    }

    public function test_non_owner_cannot_view_the_organization_settings_page()
    {
        $user = User::factory()->role(Role::Strategist)->create();

        $response = $this->actingAs($user)->get(route('organization.edit'));

        $response->assertForbidden();
    }

    public function test_owner_can_update_the_organization_settings()
    {
        $user = User::factory()->role(Role::Owner)->create();

        $response = $this->actingAs($user)->put(route('organization.update'), [
            'default_timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
            'upload_post_key' => 'up-secret-key',
            'xai_key' => 'xai-secret-key',
        ]);

        $response->assertRedirect(route('organization.edit'));

        $organization = $user->organization->fresh();
        $this->assertSame('Europe/Berlin', $organization->default_timezone);
        $this->assertSame('EUR', $organization->default_currency);
        $this->assertSame('up-secret-key', $organization->upload_post_key);
        $this->assertSame('xai-secret-key', $organization->xai_key);
    }

    public function test_blank_keys_do_not_overwrite_existing_stored_keys()
    {
        $user = User::factory()->role(Role::Owner)->create();
        $user->organization->update([
            'upload_post_key' => 'existing-upload-post-key',
            'xai_key' => 'existing-xai-key',
        ]);

        $response = $this->actingAs($user)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'upload_post_key' => '',
            'xai_key' => '',
        ]);

        $response->assertRedirect(route('organization.edit'));

        $organization = $user->organization->fresh();
        $this->assertSame('existing-upload-post-key', $organization->upload_post_key);
        $this->assertSame('existing-xai-key', $organization->xai_key);
    }

    public function test_non_owner_cannot_update_the_organization_settings()
    {
        $user = User::factory()->role(Role::Strategist)->create();

        $response = $this->actingAs($user)->put(route('organization.update'), [
            'default_timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
        ]);

        $response->assertForbidden();
    }

    public function test_a_user_cannot_update_another_organizations_settings()
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $otherOrganization = Organization::factory()->create();

        $response = $this->actingAs($owner)->put(route('organization.update'), [
            'org_id' => $otherOrganization->id,
            'default_timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
        ]);

        $response->assertRedirect(route('organization.edit'));
        $this->assertNotSame('Europe/Berlin', $otherOrganization->fresh()->default_timezone);
    }

    public function test_secret_keys_never_appear_in_the_rendered_pages_json_props()
    {
        $user = User::factory()->role(Role::Owner)->create();
        $user->organization->update([
            'upload_post_key' => 'super-secret-upload-post-key',
            'xai_key' => 'super-secret-xai-key',
        ]);

        $response = $this->actingAs($user)->get(route('organization.edit'));

        $response->assertOk();
        $response->assertDontSee('super-secret-upload-post-key', false);
        $response->assertDontSee('super-secret-xai-key', false);
    }
}
