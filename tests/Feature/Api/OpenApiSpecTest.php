<?php

namespace Tests\Feature\Api;

use App\Enums\WebhookEvent;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The spec has to describe the API that exists.
 *
 * A hand-written OpenAPI document is worth more than a generated one — it can explain *why* an
 * endpoint behaves as it does, which no generator can infer — but it drifts the moment somebody
 * adds a route. So the routes are the source of truth for *coverage*, and this test fails if the
 * two disagree in either direction: an undocumented endpoint, or a documented one that no longer
 * exists.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = resource_path('api/openapi.json');
        $this->assertFileExists($path);

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $spec;
    }

    public function test_the_spec_is_valid_json_and_served(): void
    {
        $this->assertSame('3.1.0', $this->spec()['openapi']);

        // Unauthenticated: somebody reading the docs has no token yet.
        $this->get('/api/v1/openapi.json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_every_v1_endpoint_is_documented_and_nothing_extra_is(): void
    {
        $documented = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $actual = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            // The spec describes itself nowhere, which is correct — it is documentation, not API.
            if (! str_starts_with($name, 'api.v1.') || $name === 'api.v1.openapi') {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                // Spec paths are relative to the `/api/v1` server URL.
                $actual[] = $method.' /'.ltrim(str_replace('api/v1', '', $route->uri()), '/');
            }
        }

        sort($documented);
        sort($actual);

        $this->assertSame(
            $actual,
            $documented,
            'The OpenAPI spec and routes/api.php disagree. Update resources/api/openapi.json.',
        );
    }

    public function test_the_documented_webhook_events_are_the_ones_that_exist(): void
    {
        $spec = $this->spec();
        /** @var list<string> $documented */
        $documented = $spec['paths']['/webhooks']['post']['requestBody']['content']['application/json']['schema']['properties']['events']['items']['enum'];

        sort($documented);
        $actual = WebhookEvent::values();
        sort($actual);

        // Offering an event nothing emits is worse than not offering it: an integration subscribes
        // and then waits forever.
        $this->assertSame($actual, $documented);
    }
}
