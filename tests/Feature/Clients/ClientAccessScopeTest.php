<?php

namespace Tests\Feature\Clients;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Scopes\ClientAccessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-client staff access: a teammate put on one client cannot see another's work.
 *
 * Two layers, because they protect different things. `ClientAccessScope` filters every list and
 * lookup query. The `Gate::before` in `AppServiceProvider` covers single records, because
 * route-model binding deliberately resolves without scopes so a policy can answer 403 — without it
 * a restricted member could reach another client's post by typing its URL.
 *
 * Off by default: an existing organization is unaffected until it turns the flag on.
 */
class ClientAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $mine;

    private Client $theirs;

    private User $designer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['restrict_staff_to_assigned_clients' => true]);
        $this->mine = Client::factory()->for($this->org, 'organization')->create(['name' => 'Assigned brand']);
        $this->theirs = Client::factory()->for($this->org, 'organization')->create(['name' => 'Other brand']);

        $this->designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->designer->assignedClients()->attach($this->mine);
    }

    // --- the flag ----------------------------------------------------------------------------

    public function test_nothing_is_restricted_until_the_organization_turns_it_on(): void
    {
        $this->org->update(['restrict_staff_to_assigned_clients' => false]);

        $this->assertNull($this->designer->fresh()->accessibleClientIds());
        $this->assertSame(2, Client::query()->count());
    }

    public function test_an_owner_always_sees_the_whole_roster(): void
    {
        // Somebody has to. An Owner who could lock themselves out of their own clients would be a
        // worse bug than the one this feature fixes.
        $this->assertNull($this->owner->accessibleClientIds());

        $this->actingAs($this->owner);
        $this->assertSame(2, Client::query()->count());
    }

    public function test_a_super_admin_is_never_restricted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertNull($superAdmin->accessibleClientIds());
    }

    // --- query filtering ---------------------------------------------------------------------

    public function test_a_restricted_designer_sees_only_their_assigned_clients(): void
    {
        $this->actingAs($this->designer);

        $this->assertSame([$this->mine->id], Client::query()->pluck('id')->all());
    }

    public function test_a_restricted_designer_sees_only_their_clients_posts_and_records(): void
    {
        $mine = Post::factory()->for($this->mine)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        Post::factory()->for($this->theirs)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        Campaign::factory()->for($this->theirs)->create(['org_id' => $this->org->id]);
        Asset::factory()->for($this->theirs)->create(['org_id' => $this->org->id]);
        Invoice::factory()->for($this->theirs)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->designer);

        $this->assertSame([$mine->id], Post::query()->pluck('id')->all());
        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_assigned_to_nothing_means_nothing_not_everything(): void
    {
        $newStarter = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();

        $this->assertSame([], $newStarter->accessibleClientIds());

        $this->actingAs($newStarter);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_the_roster_page_lists_only_assigned_clients(): void
    {
        $this->actingAs($this->designer)->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('clients.data', 1)
                ->where('clients.data.0.id', $this->mine->id));
    }

    // --- single records ----------------------------------------------------------------------

    public function test_a_restricted_designer_cannot_open_another_clients_record_by_url(): void
    {
        $theirPost = Post::factory()->for($this->theirs)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        // Route-model binding resolves without scopes so the policy can answer — this is the gap
        // the `Gate::before` closes.
        $this->actingAs($this->designer)->get(route('posts.edit', $theirPost))->assertForbidden();
        $this->actingAs($this->designer)->get(route('clients.show', $this->theirs))->assertForbidden();
    }

    public function test_the_same_member_can_open_their_own_clients_record(): void
    {
        $myPost = Post::factory()->for($this->mine)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($this->designer)->get(route('posts.edit', $myPost))->assertOk();
    }

    public function test_the_gate_denies_any_ability_on_another_clients_record(): void
    {
        $theirPost = Post::factory()->for($this->theirs)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        // Every ability at once, not one route: that is the point of doing it in `Gate::before`.
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse(
                Gate::forUser($this->designer)->allows($ability, $theirPost),
                "Designer should not be able to {$ability} another client's post.",
            );
        }

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $theirPost));
    }

    public function test_a_create_check_is_not_blocked_by_the_gate(): void
    {
        // An unsaved model belongs to no client yet, so the restriction has nothing to say about
        // it; denying it would block every create check in the app.
        $this->assertTrue(ClientAccessScope::allowsClientId($this->designer, null));
        $this->assertTrue(ClientAccessScope::allows($this->designer, new Client));

        // And a role that may create one still can — the `Gate::before` returns null here and lets
        // the real policy decide.
        $this->assertTrue(Gate::forUser($this->owner)->allows('create', Client::class));
    }

    // --- managing assignments ----------------------------------------------------------------

    public function test_an_owner_assigns_clients_on_the_staff_edit_page(): void
    {
        $this->actingAs($this->owner)->get(route('staff.edit', $this->designer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('restrictsClients', true)
                ->has('clients', 2)
                ->where('assignedClientIds', [$this->mine->id]));

        $this->actingAs($this->owner)->put(route('staff.update', $this->designer), [
            'name' => $this->designer->name,
            'role' => Role::Designer->value,
            'client_ids' => [$this->theirs->id],
        ])->assertRedirect(route('staff.show', $this->designer));

        $this->assertSame([$this->theirs->id], $this->designer->fresh()->accessibleClientIds());
    }

    public function test_a_client_from_another_organization_cannot_be_assigned(): void
    {
        $otherOrgClient = Client::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->create();

        $this->actingAs($this->owner)->put(route('staff.update', $this->designer), [
            'name' => $this->designer->name,
            'role' => Role::Designer->value,
            'client_ids' => [$otherOrgClient->id],
        ])->assertSessionHasErrors('client_ids.0');

        $this->assertSame([$this->mine->id], $this->designer->fresh()->accessibleClientIds());
    }

    public function test_the_picker_is_hidden_while_the_organization_does_not_restrict_access(): void
    {
        $this->org->update(['restrict_staff_to_assigned_clients' => false]);

        $this->actingAs($this->owner)->get(route('staff.edit', $this->designer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('restrictsClients', false)
                ->where('clients', [])
                ->where('assignedClientIds', []));
    }

    public function test_an_owner_turns_the_restriction_on_from_settings(): void
    {
        $this->org->update(['restrict_staff_to_assigned_clients' => false]);

        $this->confirmPassword()
            ->actingAs($this->owner)
            ->put(route('organization.update'), [
                'default_timezone' => 'UTC',
                'default_currency' => 'GBP',
                'restrict_staff_to_assigned_clients' => true,
            ])->assertRedirect(route('organization.edit'));

        $this->assertTrue($this->org->fresh()->restrict_staff_to_assigned_clients);
    }

    // --- the drift guard ---------------------------------------------------------------------

    /**
     * Every table with a `client_id` column must either be covered by the scope or listed as
     * exempt with a reason. The failure mode of a missing entry is a confidentiality hole that
     * looks like nothing at all, so it is checked rather than reviewed.
     */
    public function test_every_client_owned_table_is_covered_or_exempt(): void
    {
        $covered = [];

        foreach (ClientAccessScope::MODELS as $class) {
            /** @var Model $model */
            $model = new $class;
            $covered[] = $model->getTable();
        }

        $uncovered = [];

        foreach ($this->tablesOnThisConnection() as $table) {
            if (! in_array('client_id', Schema::getColumnListing($table), true)) {
                continue;
            }

            if (in_array($table, $covered, true) || array_key_exists($table, ClientAccessScope::EXEMPT)) {
                continue;
            }

            $uncovered[] = $table;
        }

        $this->assertSame([], $uncovered, sprintf(
            'These tables name a client but are neither scoped nor exempt: %s. Add them to '
            .'ClientAccessScope::MODELS, or to EXEMPT with the reason.',
            implode(', ', $uncovered),
        ));
    }

    public function test_every_scoped_model_actually_has_the_column_it_is_filtered_on(): void
    {
        foreach (ClientAccessScope::MODELS as $class) {
            /** @var Model $model */
            $model = new $class;
            $column = $model->getTable() === 'clients' ? 'id' : 'client_id';

            $this->assertContains(
                $column,
                Schema::getColumnListing($model->getTable()),
                "{$class} is scoped on {$column}, which its table does not have.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function tablesOnThisConnection(): array
    {
        $connection = DB::connection();

        $tables = $connection->getDriverName() === 'sqlite'
            ? Schema::getTables()
            : Schema::getTables($connection->getDatabaseName());

        return array_values(array_column($tables, 'name'));
    }
}
