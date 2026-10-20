<?php

namespace Tests\Feature\Grids;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\ApprovalMode;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Role;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.1 — grid plan schema, factories, relations, the review-token rule and the policy
 * matrix across all six roles.
 */
class GridPlanModelAndPolicyTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
    }

    public function test_factories_build_a_plan_with_tiles_media_and_casts(): void
    {
        $plan = GridPlan::factory()->create();
        $tile = GridTile::factory()->for($plan, 'plan')->reel()->create();

        $this->assertSame(GridPlanStatus::Draft, $plan->status);
        $this->assertNotNull($plan->socialAccount);
        $this->assertSame('instagram', $plan->socialAccount->platform->value);
        $this->assertSame($plan->client->org_id, $plan->org_id);
        $this->assertSame(GridTileKind::Reel, $tile->kind);
        $this->assertSame(MediaRatio::Vertical, $tile->format);

        $built = $this->planWithTiles(2)->load('tiles.assets');
        $this->assertCount(2, $built->tiles);
        $this->assertSame([1, 2], $built->tiles->pluck('position')->all());
        $this->assertSame(0.5, (float) $built->tiles[0]->assets->first()->pivot->crop_x);
    }

    public function test_positions_are_unique_per_plan(): void
    {
        $plan = $this->planWithTiles(1);

        $this->expectException(QueryException::class);
        $plan->tiles()->create(['position' => 1, 'kind' => GridTileKind::Image, 'format' => MediaRatio::Classic]);
    }

    public function test_a_post_knows_the_tile_it_came_from_and_deleting_it_frees_the_tile(): void
    {
        $plan = $this->planWithTiles(1);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $plan->tiles[0]->update(['post_id' => $post->id]);

        $this->assertSame($plan->tiles[0]->id, $post->gridTile?->id);

        $post->delete();
        $this->assertNull($plan->tiles[0]->fresh()?->post_id);

        // A hard delete is covered by the foreign key too.
        $other = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $plan->tiles[0]->update(['post_id' => $other->id]);
        $other->forceDelete();
        $this->assertNull($plan->tiles[0]->fresh()?->post_id);
    }

    public function test_a_review_token_carries_a_post_or_a_grid_plan_never_both(): void
    {
        $plan = $this->makePlan();
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $create = new CreateReviewTokenAction;

        $token = $create($this->client, gridPlan: $plan)['model'];
        $this->assertSame($plan->id, $token->grid_plan_id);
        $this->assertNull($token->post_id);

        $this->expectException(InvalidArgumentException::class);
        $create($this->client, $post, gridPlan: $plan);
    }

    public function test_a_review_token_never_points_at_another_clients_grid(): void
    {
        $other = Client::factory()->for($this->org, 'organization')->create();

        $this->expectException(InvalidArgumentException::class);
        (new CreateReviewTokenAction)($other, gridPlan: $this->makePlan());
    }

    /**
     * @return array<string, array{string, array<string, bool>}>
     */
    public static function roleMatrix(): array
    {
        $editor = ['view' => true, 'create' => true, 'update' => true, 'transition' => true, 'produce' => true];

        return [
            'owner' => ['owner', [...$editor, 'delete' => true]],
            'strategist' => ['strategist', [...$editor, 'delete' => true]],
            'designer' => ['designer', [...$editor, 'delete' => false]],
            'viewer' => ['viewer', ['view' => true, 'create' => false, 'update' => false, 'transition' => false, 'produce' => false, 'delete' => false]],
            'client reviewer' => ['client_reviewer', ['view' => false, 'create' => false, 'update' => false, 'transition' => false, 'produce' => false, 'delete' => false]],
            'super admin' => ['super_admin', ['view' => true, 'create' => true, 'update' => true, 'transition' => true, 'produce' => true, 'delete' => true]],
        ];
    }

    /**
     * @param  array<string, bool>  $expected
     */
    #[DataProvider('roleMatrix')]
    public function test_policy_matrix_on_a_draft_grid(string $role, array $expected): void
    {
        $user = $this->userFor(Role::from($role));
        $plan = $this->makePlan();

        $this->assertSame($expected['view'], $user->can('view', $plan), 'view');
        $this->assertSame($expected['create'], $user->can('create', [GridPlan::class, $this->client]), 'create');
        $this->assertSame($expected['update'], $user->can('update', $plan), 'update');
        $this->assertSame($expected['transition'], $user->can('transition', [$plan, GridPlanStatus::InternalReview]), 'transition');
        $this->assertSame($expected['produce'], $user->can('produce', $plan), 'produce');
        $this->assertSame($expected['delete'], $user->can('delete', $plan), 'delete');
    }

    public function test_a_client_reviewer_sees_their_own_clients_grid_only_once_it_is_sent_to_them(): void
    {
        $plan = $this->makePlan(['status' => GridPlanStatus::WaitingClient]);

        $this->assertTrue($this->reviewer->can('view', $plan));
        $this->assertTrue($this->reviewer->can('transition', [$plan, GridPlanStatus::Approved]));
        $this->assertTrue($this->reviewer->can('transition', [$plan, GridPlanStatus::ChangesRequested]));
        $this->assertFalse($this->reviewer->can('transition', [$plan, GridPlanStatus::Draft]));
        $this->assertFalse($this->reviewer->can('update', $plan));

        foreach ([GridPlanStatus::Draft, GridPlanStatus::InternalReview] as $status) {
            $plan->update(['status' => $status]);
            $this->assertFalse($this->reviewer->can('view', $plan), "{$status->value} must stay hidden");
        }

        // Another client's grid, whatever its status.
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $other = GridPlan::factory()->create(['client_id' => $otherClient->id, 'status' => GridPlanStatus::WaitingClient]);
        $this->assertFalse($this->reviewer->can('view', $other));
        $this->assertFalse($this->reviewer->can('transition', [$other, GridPlanStatus::Approved]));
        $this->assertFalse($this->reviewer->can('viewAny', [GridPlan::class, $otherClient]));
    }

    public function test_staff_of_another_organization_are_refused_everything(): void
    {
        $outsider = User::factory()->role(Role::Owner)->create();
        $plan = $this->makePlan();

        foreach (['view', 'update', 'produce', 'delete'] as $ability) {
            $this->assertFalse($outsider->can($ability, $plan), $ability);
        }
        $this->assertFalse($outsider->can('create', [GridPlan::class, $this->client]));
    }

    public function test_a_grid_out_with_the_client_or_archived_is_locked_for_edits(): void
    {
        foreach ([GridPlanStatus::WaitingClient, GridPlanStatus::Archived] as $status) {
            $plan = $this->makePlan(['status' => $status]);
            $this->assertFalse($this->owner()->can('update', $plan), $status->value);
        }

        foreach ([GridPlanStatus::Draft, GridPlanStatus::InternalReview, GridPlanStatus::ChangesRequested, GridPlanStatus::Approved] as $status) {
            $plan = $this->makePlan(['status' => $status]);
            $this->assertTrue($this->designer()->can('update', $plan), $status->value);
        }
    }

    public function test_only_an_owner_can_override_client_approval_and_only_when_it_is_required(): void
    {
        $this->client->update(['default_approval_mode' => ApprovalMode::ClientRequired]);
        $plan = $this->makePlan(['status' => GridPlanStatus::InternalReview]);

        $this->assertTrue($this->owner()->can('overrideApproval', $plan));
        $this->assertFalse($this->strategist()->can('overrideApproval', $plan));

        $this->client->update(['default_approval_mode' => ApprovalMode::InternalOnly]);
        $this->assertFalse($this->owner()->can('overrideApproval', $plan->fresh()));
    }

    private function userFor(Role $role): User
    {
        return match ($role) {
            Role::ClientReviewer => $this->reviewer,
            Role::SuperAdmin => User::factory()->superAdmin()->create(),
            default => $this->staff[$role->value],
        };
    }
}
