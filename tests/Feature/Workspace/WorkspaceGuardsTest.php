<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Step B-1.4 — generated from the router: every route carrying `workspace:agency` must refuse a
 * business organization. New guarded routes are covered the moment they're added.
 */
class WorkspaceGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmPassword();
    }

    public function test_a_business_org_is_refused_from_every_agency_only_route(): void
    {
        $organization = Organization::factory()->business()->create();
        (new CreatePrimaryBrandAction)($organization);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        /** @var Router $router */
        $router = app(Router::class);
        $checked = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! $this->isAgencyGuarded($router, $route)) {
                continue;
            }

            // The `portal.*` group's own `portal` middleware refuses a non-reviewer first (its
            // `workspace:agency` is belt-and-braces); those routes are covered by portal tests.
            if (str_starts_with((string) $route->getName(), 'portal.')) {
                continue;
            }

            $method = collect($route->methods())->first(fn (string $m) => $m !== 'HEAD') ?? 'GET';
            $url = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            $status = $this->actingAs($owner)->call($method, $url)->getStatusCode();
            $checked++;

            // Redirect to a business equivalent (none yet) or a 404 — never a 200, never a bare 403.
            if (! in_array($status, [404, 302], true)) {
                $failures[] = "{$route->getName()} ({$method} {$route->uri()}) → {$status}";
            }
        }

        $this->assertGreaterThan(20, $checked, 'Expected the agency-only route set to be substantial.');
        $this->assertSame([], $failures, 'Agency-only routes reachable by a business org: '.implode(', ', $failures));
    }

    public function test_an_agency_org_still_reaches_agency_only_pages(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('clients.index'))->assertOk();
        $this->actingAs($owner)->get(route('invoices.index'))->assertOk();
    }

    private function isAgencyGuarded(Router $router, RoutingRoute $route): bool
    {
        return collect($router->gatherRouteMiddleware($route))->contains(
            fn ($middleware) => is_string($middleware) && str_contains($middleware, 'EnsureWorkspaceType:agency'),
        );
    }
}
