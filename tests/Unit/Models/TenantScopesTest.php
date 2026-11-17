<?php

namespace Tests\Unit\Models;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Scopes\TenantScopes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `TenantScopes::suspended()` — the two access scopes (`OrganizationScope`, `ClientAccessScope`)
 * apply to nobody inside the callback and to everybody again after it, however it ends.
 */
class TenantScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_are_suspended_inside_the_callback_only_and_its_value_is_returned(): void
    {
        $this->assertFalse(TenantScopes::areSuspended());

        $result = TenantScopes::suspended(fn (): array => ['inside' => TenantScopes::areSuspended()]);

        $this->assertSame(['inside' => true], $result);
        $this->assertFalse(TenantScopes::areSuspended());
    }

    public function test_it_nests_and_stays_suspended_until_the_outermost_call_returns(): void
    {
        $seen = TenantScopes::suspended(function (): array {
            $inner = TenantScopes::suspended(fn (): bool => TenantScopes::areSuspended());

            // The inner call returning must not switch the scopes back on for the outer one.
            return ['inner' => $inner, 'after_inner' => TenantScopes::areSuspended()];
        });

        $this->assertSame(['inner' => true, 'after_inner' => true], $seen);
        $this->assertFalse(TenantScopes::areSuspended());
    }

    public function test_scopes_are_restored_after_an_exception_inside_the_callback(): void
    {
        $caught = null;

        try {
            TenantScopes::suspended(function (): void {
                TenantScopes::suspended(function (): void {
                    throw new RuntimeException('inner');
                });
            });
        } catch (RuntimeException $e) {
            $caught = $e->getMessage();
        }

        $this->assertSame('inner', $caught, 'The exception was swallowed.');
        $this->assertFalse(TenantScopes::areSuspended());
    }

    public function test_the_organization_scope_applies_outside_and_not_inside(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();
        $myClient = Client::factory()->for($mine, 'organization')->create();
        $theirClient = Client::factory()->for($theirs, 'organization')->create();
        Post::factory()->for($myClient)->create(['org_id' => $mine->id]);
        $theirPost = Post::factory()->for($theirClient)->create(['org_id' => $theirs->id]);

        $this->actingAs(User::factory()->for($mine, 'organization')->role(Role::Owner)->create());

        $this->assertSame([$myClient->id], Client::query()->pluck('id')->all());
        $this->assertNull(Post::query()->find($theirPost->id));

        [$clients, $found] = TenantScopes::suspended(fn (): array => [
            Client::query()->orderBy('id')->pluck('id')->all(),
            Post::query()->find($theirPost->id)?->id,
        ]);

        $this->assertSame([$myClient->id, $theirClient->id], $clients);
        $this->assertSame($theirPost->id, $found);

        // And back on afterwards.
        $this->assertSame([$myClient->id], Client::query()->pluck('id')->all());
        $this->assertNull(Post::query()->find($theirPost->id));
    }

    public function test_the_client_access_scope_applies_outside_and_not_inside(): void
    {
        $org = Organization::factory()->create(['restrict_staff_to_assigned_clients' => true]);
        $assigned = Client::factory()->for($org, 'organization')->create();
        $other = Client::factory()->for($org, 'organization')->create();
        Post::factory()->for($assigned)->create(['org_id' => $org->id]);
        $otherPost = Post::factory()->for($other)->create(['org_id' => $org->id]);

        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $designer->assignedClients()->attach($assigned);
        $this->actingAs($designer);

        $this->assertSame([$assigned->id], Client::query()->pluck('id')->all());
        $this->assertNull(Post::query()->find($otherPost->id));

        [$clients, $found] = TenantScopes::suspended(fn (): array => [
            Client::query()->orderBy('id')->pluck('id')->all(),
            Post::query()->find($otherPost->id)?->id,
        ]);

        $this->assertSame([$assigned->id, $other->id], $clients);
        $this->assertSame($otherPost->id, $found);

        $this->assertSame([$assigned->id], Client::query()->pluck('id')->all());
        $this->assertNull(Post::query()->find($otherPost->id));
    }

    public function test_scopes_apply_again_after_a_query_inside_the_callback_throws(): void
    {
        $mine = Organization::factory()->create();
        $theirClient = Client::factory()->for(Organization::factory()->create(), 'organization')->create();
        $this->actingAs(User::factory()->for($mine, 'organization')->role(Role::Owner)->create());

        $foundInside = null;

        try {
            TenantScopes::suspended(function () use ($theirClient, &$foundInside): void {
                $foundInside = Client::query()->find($theirClient->id)?->id;

                throw new RuntimeException('after the query');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($theirClient->id, $foundInside);
        $this->assertNull(Client::query()->find($theirClient->id));
    }
}
