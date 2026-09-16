<?php

namespace Tests\Feature;

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

    public function test_preview_mode_rejects_write_requests_with_a_403()
    {
        $this->seed(PreviewSeeder::class);

        $client = \App\Models\Client::firstWhere('name', 'Fixture & Co');

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
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('preview', false));
    }
}
