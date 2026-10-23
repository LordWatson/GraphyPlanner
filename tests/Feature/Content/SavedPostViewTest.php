<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\SavedPostView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-5.1 — personal saved views on the content hub: create, list (own only), replace by name
 * and delete (own only).
 */
class SavedPostViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    public function test_a_member_can_save_and_see_their_views(): void
    {
        $this->actingAs($this->owner)->post(route('content.views.store'), [
            'name' => 'Waiting on clients',
            'filters' => ['status' => 'waiting_client'],
        ])->assertRedirect();

        $this->assertDatabaseHas('saved_post_views', [
            'user_id' => $this->owner->id,
            'org_id' => $this->org->id,
            'name' => 'Waiting on clients',
        ]);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertInertia(fn ($page) => $page->has('savedViews', 1)
                ->where('savedViews.0.name', 'Waiting on clients'));
    }

    public function test_saving_the_same_name_replaces_the_view(): void
    {
        $payload = fn (array $filters) => [
            'name' => 'My view',
            'filters' => $filters,
        ];

        $this->actingAs($this->owner)->post(route('content.views.store'), $payload(['status' => 'draft']));
        $this->actingAs($this->owner)->post(route('content.views.store'), $payload(['status' => 'approved']));

        $this->assertSame(1, SavedPostView::query()->where('user_id', $this->owner->id)->count());
        $this->assertSame(
            ['status' => 'approved'],
            SavedPostView::query()->where('user_id', $this->owner->id)->sole()->filters,
        );
    }

    public function test_a_member_only_sees_their_own_views(): void
    {
        $colleague = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        SavedPostView::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $colleague->id,
            'name' => 'Colleague view',
        ]);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertInertia(fn ($page) => $page->has('savedViews', 0));
    }

    public function test_a_member_cannot_delete_another_members_view(): void
    {
        $colleague = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $view = SavedPostView::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $colleague->id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('content.views.destroy', $view))
            ->assertForbidden();

        $this->assertModelExists($view);
    }
}
