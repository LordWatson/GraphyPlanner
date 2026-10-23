<?php

namespace Tests\Unit\Services\Posts;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Services\Posts\AgencyPostWorkflow;
use App\Services\Posts\BusinessPostWorkflow;
use App\Services\Posts\PostWorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step B-3.1 — the per-org-type post workflow. The agency workflow must stay byte-for-byte the
 * old `PostStatusTransitionMap`; the business workflow drops the client stages and branches on the
 * review switch.
 */
class PostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: for every status, the agency workflow returns exactly what the raw map did
     * before B-3.1, so introducing the contract changed no agency transition.
     */
    public function test_agency_workflow_matches_the_raw_transition_map_for_every_status(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $workflow = new AgencyPostWorkflow;

        foreach (PostStatus::cases() as $status) {
            $post = Post::factory()->for($client)->make([
                'org_id' => $organization->id,
                'status' => $status,
                'approval_mode' => ApprovalMode::ClientRequired,
            ]);

            $this->assertSame(
                PostStatusTransitionMap::allowedFor($post),
                $workflow->allowedTransitions($post),
                "Agency workflow drifted from the map at {$status->value}",
            );
        }
    }

    public function test_agency_default_approval_mode_is_client_required(): void
    {
        $this->assertSame(ApprovalMode::ClientRequired, (new AgencyPostWorkflow)->defaultApprovalMode());
        $this->assertSame(ApprovalMode::InternalOnly, (new BusinessPostWorkflow)->defaultApprovalMode());
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('businessReviewOffTransitions')]
    public function test_business_workflow_with_review_off(string $from, array $expected): void
    {
        $this->assertBusinessTransitions(false, $from, $expected);
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('businessReviewOnTransitions')]
    public function test_business_workflow_with_review_on(string $from, array $expected): void
    {
        $this->assertBusinessTransitions(true, $from, $expected);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function businessReviewOffTransitions(): array
    {
        return [
            // Review off: a draft self-approves straight to approved — the core B-3 move.
            'idea' => ['idea', ['draft', 'archived']],
            'draft' => ['draft', ['approved', 'archived']],
            'internal_review' => ['internal_review', ['draft', 'approved', 'archived']],
            'approved' => ['approved', ['scheduled', 'draft', 'archived']],
            'scheduled' => ['scheduled', ['publishing', 'approved', 'draft', 'archived']],
            'published' => ['published', ['archived']],
            'archived' => ['archived', ['draft']],
            // The two client stages are never reachable in a business workspace.
            'waiting_client' => ['waiting_client', []],
            'changes_requested' => ['changes_requested', []],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function businessReviewOnTransitions(): array
    {
        return [
            // Review on: a draft goes to review first; the rest of the map is unchanged.
            'draft' => ['draft', ['internal_review', 'archived']],
            'internal_review' => ['internal_review', ['draft', 'approved', 'archived']],
            'idea' => ['idea', ['draft', 'archived']],
            'approved' => ['approved', ['scheduled', 'draft', 'archived']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertBusinessTransitions(bool $reviewOn, string $from, array $expected): void
    {
        $organization = Organization::factory()->business()->create(['require_post_review' => $reviewOn]);
        $client = Client::factory()->for($organization, 'organization')->primary()->create();
        $post = Post::factory()->for($client)->make([
            'org_id' => $organization->id,
            'status' => PostStatus::from($from),
            'approval_mode' => ApprovalMode::InternalOnly,
        ]);
        $post->setRelation('organization', $organization);

        $actual = array_map(
            fn (PostStatus $status) => $status->value,
            (new BusinessPostWorkflow)->allowedTransitions($post),
        );

        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_resolver_picks_the_workflow_by_org_type(): void
    {
        $resolver = app(PostWorkflowResolver::class);

        $agency = Organization::factory()->create();
        $business = Organization::factory()->business()->create();

        $this->assertInstanceOf(AgencyPostWorkflow::class, $resolver->for($agency));
        $this->assertInstanceOf(BusinessPostWorkflow::class, $resolver->for($business));
    }

    public function test_requires_review_reads_the_business_switch(): void
    {
        $workflow = new BusinessPostWorkflow;

        $off = Organization::factory()->business()->create(['require_post_review' => false]);
        $on = Organization::factory()->business()->create(['require_post_review' => true]);

        $this->assertFalse($workflow->requiresReview($off));
        $this->assertTrue($workflow->requiresReview($on));
        // An agency always has a review stage (the flag is never consulted for it).
        $this->assertTrue((new AgencyPostWorkflow)->requiresReview(Organization::factory()->create()));
    }
}
