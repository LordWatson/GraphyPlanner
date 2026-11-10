<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_about_redirects_to_the_homepage()
    {
        // The standalone About page was folded into the redesigned homepage (features, pricing,
        // FAQ), so /about now redirects home. The `about` route name is kept for existing links.
        $response = $this->get(route('about'));

        $response->assertRedirect(route('home'));
    }
}
