<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_dashboard_is_visible_without_logging_in_and_shows_the_banner()
    {
        $this->seed(PreviewSeeder::class);

        $response = $this->get(route('preview.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('preview', true));
    }

    /**
     * Regression: shared props used to read `$request->user()` before the route-level `preview`
     * middleware had signed the visitor in, so a visitor's first `/preview/*` request in a fresh
     * session shared `auth.user = null` and the sidebar crashed on `auth.user.role`.
     */
    public function test_first_preview_request_shares_the_signed_in_preview_owner()
    {
        $this->seed(PreviewSeeder::class);

        $this->assertGuest();

        $response = $this->get(route('preview.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'preview@graphy.test')
            ->where('auth.user.role', 'owner')
            ->where('unreadNotificationsCount', 0)
        );
    }

    public function test_preview_mode_rejects_write_requests_with_a_403()
    {
        $this->seed(PreviewSeeder::class);

        $client = Client::firstWhere('name', 'Fixture & Co');

        $response = $this->post(route('preview.clients.store'), [
            'name' => 'Should Never Be Created',
        ]);
        $response->assertForbidden();

        $response = $this->put(route('preview.clients.update', $client), [
            'name' => 'Tampered Name',
        ]);
        $response->assertForbidden();

        $response = $this->delete(route('preview.clients.destroy', $client));
        $response->assertForbidden();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Fixture & Co']);
        $this->assertDatabaseMissing('clients', ['name' => 'Should Never Be Created']);
    }

    public function test_non_preview_routes_do_not_share_the_preview_flag()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('preview', false));
    }

    /**
     * Regression (Step I-1.1): `EnsurePreviewMode` used `Auth::login()`, which persisted the
     * preview owner in the session — one visit to `/preview/dashboard` signed the visitor in on
     * the real, writable routes too.
     */
    public function test_visiting_preview_does_not_sign_the_visitor_in_on_real_routes()
    {
        $this->seed(PreviewSeeder::class);

        $this->get(route('preview.dashboard'))->assertOk();

        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_logged_in_owner_stays_themselves_after_visiting_preview()
    {
        $this->seed(PreviewSeeder::class);

        $owner = User::factory()->create(['role' => Role::Owner]);
        $this->actingAs($owner);

        $this->get(route('preview.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.email', User::PREVIEW_EMAIL));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.id', $owner->id)
                ->where('preview', false)
            );
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_persisted_preview_session_is_rejected_and_signed_out_outside_preview()
    {
        $this->seed(PreviewSeeder::class);

        $previewUser = User::firstWhere('email', User::PREVIEW_EMAIL);
        $this->actingAs($previewUser);

        $this->get(route('clients.index'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_the_preview_org_is_never_writable_through_real_routes_after_a_preview_visit()
    {
        $this->seed(PreviewSeeder::class);

        $previewClient = Client::query()
            ->where('org_id', Organization::firstWhere('slug', Organization::PREVIEW_SLUG)->id)
            ->firstOrFail();

        $this->get(route('preview.clients.show', $previewClient))->assertOk();

        $this->put(route('clients.update', $previewClient), ['name' => 'Tampered'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseHas('clients', ['id' => $previewClient->id, 'name' => 'Fixture & Co']);
    }
}
