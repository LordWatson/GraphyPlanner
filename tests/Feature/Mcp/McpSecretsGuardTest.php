<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Resources\BrandBrainResource;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\ListClientsTool;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Primitive;
use ReflectionClass;
use Tests\TestCase;

/**
 * Step MCP-1.6 — the automated secrets-exposure guard (the MCP analogue of the review-portal
 * props-payload test). It enumerates every tool/resource registered on `GraphyServer` and asserts
 * (a) a new primitive forces this guard to be revisited, (b) no declared input/output schema field
 * can carry a secret, and (c) the real output of each read primitive — invoked against seeded data
 * that deliberately includes a connected account's access token — leaks neither that token nor any
 * secret-shaped field name. Runs in `composer test` (PHPUnit over `tests/`) as part of CI.
 */
class McpSecretsGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Field-name fragments that must never appear in an MCP schema or output. Specific on purpose
     * (e.g. `access_token`, not bare `token`) so legitimate copy doesn't trip the guard.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'meta_access_token', 'access_token', 'refresh_token', 'token_hash',
        'api_key', 'apikey', 'client_secret', 'password', 'webhook_secret',
        'private_key', 'review_token',
    ];

    public function test_a_new_tool_or_resource_forces_this_guard_to_be_revisited(): void
    {
        // If this fails, a primitive was added/removed — extend the coverage below for it, then
        // update this list. The guard must consciously cover every registered primitive.
        $this->assertEqualsCanonicalizing([ListClientsTool::class], $this->registered('tools'));
        $this->assertEqualsCanonicalizing([BrandBrainResource::class], $this->registered('resources'));
    }

    public function test_no_registered_schema_declares_a_secret_bearing_field(): void
    {
        $classes = [...$this->registered('tools'), ...$this->registered('resources')];
        $this->assertNotEmpty($classes, 'GraphyServer should register at least one primitive.');

        foreach ($classes as $class) {
            /** @var Primitive $primitive */
            $primitive = new $class;
            $definition = $primitive->toArray();

            // The declared schema field names must not look secret-bearing...
            $names = [
                ...$this->propertyNames($definition['inputSchema'] ?? []),
                ...$this->propertyNames($definition['outputSchema'] ?? []),
            ];

            foreach ($names as $name) {
                foreach (self::FORBIDDEN as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        strtolower($name),
                        "Schema field [{$name}] on [{$class}] looks like it could carry a secret.",
                    );
                }
            }

            // ...and neither must anything else in the advertised definition (name/description/schemas).
            $serialized = strtolower((string) json_encode($definition));

            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $serialized,
                    "The advertised definition of [{$class}] mentions a secret-shaped field [{$needle}].",
                );
            }
        }
    }

    public function test_read_primitive_output_leaks_no_token_or_secret_field(): void
    {
        $org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $org->id);

        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Northwind Coffee']);
        BrandBrain::factory()->for($client)->create();

        // Adversarial: a connected account whose (encrypted) access token is a known sentinel. No
        // tool/resource reads accounts, so it must never surface — this proves that.
        $sentinel = 'SENTINEL-SECRET-9f3a2b7c1d';
        SocialAccount::factory()->for($client)->connected()->create(['meta_access_token' => $sentinel]);

        $clients = GraphyServer::tool(ListClientsTool::class, [])->assertOk();
        $brandBrain = GraphyServer::resource(BrandBrainResource::class)->assertOk();

        foreach ([$sentinel, ...self::FORBIDDEN] as $needle) {
            $clients->assertDontSee($needle);
            $brandBrain->assertDontSee($needle);
        }
    }

    /**
     * The class-strings registered on the server for a given primitive kind, read straight off the
     * server definition so the guard tracks the real registration.
     *
     * @return list<class-string<Primitive>>
     */
    private function registered(string $kind): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (new ReflectionClass(GraphyServer::class))->getDefaultProperties();

        /** @var list<class-string<Primitive>> $classes */
        $classes = $defaults[$kind] ?? [];

        return $classes;
    }

    /**
     * All property names declared anywhere in a JSON schema array (recursing into nested objects).
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function propertyNames(array $schema): array
    {
        $names = [];

        foreach ($schema['properties'] ?? [] as $name => $child) {
            $names[] = (string) $name;

            if (is_array($child)) {
                $names = [...$names, ...$this->propertyNames($child)];
            }
        }

        return $names;
    }
}
