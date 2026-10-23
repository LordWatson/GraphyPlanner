<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Step M-6.2 (I-8.1) — a client reviewer lives entirely in `/portal/*`. Generated from the route
 * table (like `CrossTenantRouteTest`): every parameterless internal GET page that carries the
 * `portal.redirect` middleware must send a client reviewer to the portal, never render (200). A new
 * internal page is covered the moment it's added to the guarded workspace group.
 */
class ClientReviewerInternalRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_pages_redirect_a_client_reviewer_to_the_portal(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $client->id]);

        $checked = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            // Only parameterless GET pages guarded by `portal.redirect` (bound-parameter routes are
            // covered by CrossTenantRouteTest, which accepts the same portal redirect).
            if (
                ! in_array('GET', $route->methods(), true)
                || str_contains($route->uri(), '{')
                || ! in_array('portal.redirect', $route->gatherMiddleware(), true)
                || str_starts_with((string) $name, 'portal.')
            ) {
                continue;
            }

            $response = $this->actingAs($reviewer)->get('/'.ltrim($route->uri(), '/'));
            $checked++;

            $location = $response->headers->get('Location') ?? '';
            $redirectedToPortal = $response->getStatusCode() === 302
                && (str_contains($location, '/portal') || str_ends_with($location, '/portal'));

            if (! $redirectedToPortal && ! in_array($response->getStatusCode(), [403, 404], true)) {
                $failures[] = "{$route->uri()} → {$response->getStatusCode()} (Location: {$location})";
            }
        }

        $this->assertGreaterThan(0, $checked, 'Expected to exercise at least one guarded internal page.');
        $this->assertSame([], $failures, "Internal pages a client reviewer could reach:\n".implode("\n", $failures));
    }
}
