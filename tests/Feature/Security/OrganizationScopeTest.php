<?php

namespace Tests\Feature\Security;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step I-1.9 — `OrganizationScope`: queries on org-owned models only see the signed-in member's
 * organization; guests (jobs, commands, webhooks) and super admins are unscoped; foreign ids are
 * rejected by validation.
 */
class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        Client::factory()->for($this->orgA, 'organization')->create(['name' => 'Ours']);
        Client::factory()->for($this->orgB, 'organization')->create(['name' => 'Theirs']);
    }

    public function test_an_unscoped_looking_query_only_returns_the_members_organization(): void
    {
        $this->actingAs(User::factory()->for($this->orgA, 'organization')->role(Role::Owner)->create());

        // No `where('org_id', …)` — the scope adds it.
        $this->assertSame(['Ours'], Client::query()->pluck('name')->all());
        $this->assertNull(Client::firstWhere('name', 'Theirs'));
    }

    public function test_jobs_commands_and_guests_are_not_scoped(): void
    {
        $this->assertEqualsCanonicalizing(['Ours', 'Theirs'], Client::query()->pluck('name')->all());
    }

    public function test_super_admins_are_not_scoped(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertEqualsCanonicalizing(['Ours', 'Theirs'], Client::query()->pluck('name')->all());
    }

    public function test_without_org_scope_crosses_organizations_on_purpose(): void
    {
        $this->actingAs(User::factory()->for($this->orgA, 'organization')->role(Role::Owner)->create());

        $this->assertEqualsCanonicalizing(['Ours', 'Theirs'], Client::query()->withoutOrgScope()->pluck('name')->all());
    }

    public function test_a_record_still_resolves_its_own_parent(): void
    {
        $theirs = Client::withoutOrgScope()->firstWhere('name', 'Theirs');
        $post = Post::factory()->for($theirs)->create(['org_id' => $this->orgB->id]);

        $this->actingAs(User::factory()->for($this->orgA, 'organization')->role(Role::Owner)->create());

        // A bound/loaded record's parent is keyed by its own foreign key, so policies can inspect
        // it and answer 403 instead of crashing on a null relation.
        $this->assertSame($theirs->id, Post::withoutOrgScope()->find($post->id)?->client?->id);
    }

    public function test_another_organizations_asset_cannot_be_attached_to_a_post(): void
    {
        $owner = User::factory()->for($this->orgA, 'organization')->role(Role::Owner)->create();
        $ours = Client::withoutOrgScope()->firstWhere('name', 'Ours');
        $theirs = Client::withoutOrgScope()->firstWhere('name', 'Theirs');
        $post = Post::factory()->for($ours)->create(['org_id' => $this->orgA->id, 'status' => PostStatus::Draft]);
        $foreignAsset = Asset::factory()->for($theirs)->create(['org_id' => $this->orgB->id]);

        $this->actingAs($owner)
            ->put(route('posts.update', $post), ['asset_ids' => [$foreignAsset->id]])
            ->assertSessionHasErrors('asset_ids.0');

        $this->assertSame(0, $post->assets()->count());
    }
}
