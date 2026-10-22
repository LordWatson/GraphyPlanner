<?php

namespace Tests\Feature\Business;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\BusinessPostWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step B-3.3 — the Owner-editable "require post review" switch for a business workspace.
 */
class WorkspaceReviewSettingTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(bool $requireReview = false): array
    {
        $organization = Organization::factory()->business()->create([
            'require_post_review' => $requireReview,
        ]);
        Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        return [$organization, $owner];
    }

    public function test_owner_sees_the_workspace_settings_page(): void
    {
        [, $owner] = $this->businessOwner();

        $this->actingAs($owner)
            ->get(route('workspace-settings.edit'))
            ->assertOk();
    }

    public function test_owner_can_turn_review_on(): void
    {
        [$organization, $owner] = $this->businessOwner();

        $this->actingAs($owner)
            ->put(route('workspace-settings.update'), ['require_post_review' => true])
            ->assertRedirect(route('workspace-settings.edit'));

        $this->assertTrue($organization->refresh()->require_post_review);
    }

    public function test_toggling_the_switch_changes_the_workflow_output(): void
    {
        [$organization] = $this->businessOwner(requireReview: false);
        $brand = $organization->primaryBrand()->first();
        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
        ]);
        $post->setRelation('organization', $organization);

        $workflow = new BusinessPostWorkflow;

        // Review off: a draft self-approves.
        $this->assertContains(PostStatus::Approved, $workflow->allowedTransitions($post));
        $this->assertNotContains(PostStatus::InternalReview, $workflow->allowedTransitions($post));

        $organization->update(['require_post_review' => true]);
        $post->setRelation('organization', $organization->refresh());

        // Review on: a draft goes to review first.
        $this->assertContains(PostStatus::InternalReview, $workflow->allowedTransitions($post));
        $this->assertNotContains(PostStatus::Approved, $workflow->allowedTransitions($post));
    }

    public function test_an_in_flight_review_post_can_still_be_approved_after_switching_review_off(): void
    {
        [$organization, $owner] = $this->businessOwner(requireReview: true);
        $brand = $organization->primaryBrand()->first();
        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::InternalReview,
        ]);

        // Owner turns review off while a post sits in review.
        $organization->update(['require_post_review' => false]);

        // The post can still move forward to approved — it isn't stranded (B-3.3 Done-when).
        $this->actingAs($owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Approved->value])
            ->assertRedirect(route('posts.edit', $post));

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_a_non_owner_cannot_change_the_setting(): void
    {
        [$organization] = $this->businessOwner();
        $designer = User::factory()->for($organization, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)
            ->put(route('workspace-settings.update'), ['require_post_review' => true])
            ->assertForbidden();

        $this->assertFalse($organization->refresh()->require_post_review);
    }

    public function test_an_agency_cannot_reach_workspace_settings(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        // `workspace:business` 404s an agency rather than advertising the feature.
        $this->actingAs($owner)
            ->get(route('workspace-settings.edit'))
            ->assertNotFound();
    }
}
