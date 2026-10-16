<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branded error views in `resources/views/errors` replace Laravel's unstyled defaults.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_routes_render_the_branded_404_page()
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('This page wandered off.');
        $response->assertSee('/brand/alima_logo_cream.svg', false);
        $response->assertSee('Back to Alima');
    }

    public function test_the_403_page_shows_the_abort_message_when_one_is_given()
    {
        $this->seed(PreviewSeeder::class);

        $response = $this->post(route('preview.clients.store'), ['name' => 'Nope']);

        $response->assertForbidden();
        $response->assertSee('Not your page to see.');
        $response->assertSee('Preview mode is read-only');
    }

    public function test_signed_in_visitors_are_offered_a_way_back_to_the_studio()
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('Back to the studio');
    }
}
