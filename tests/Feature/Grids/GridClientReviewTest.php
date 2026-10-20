<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\TransitionGridPlanStatusAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\GridPlanStatus;
use App\Enums\Role;
use App\Mail\ClientGridReviewRequestedMail;
use App\Models\Client;
use App\Models\GridComment;
use App\Models\GridPlan;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.8 — client approval of a grid through the emailed review link and the client portal:
 * the email, token checks, decisions with flagged tiles, the stale-decision check, and that a
 * client never sees team notes, internal comments, billing or another client's data.
 */
class GridClientReviewTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        Mail::fake();
        Notification::fake();
    }

    public function test_sending_to_the_client_emails_a_grid_review_link(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);

        app(TransitionGridPlanStatusAction::class)($plan, GridPlanStatus::WaitingClient, $this->owner());

        Mail::assertQueued(ClientGridReviewRequestedMail::class, function (ClientGridReviewRequestedMail $mail) use ($plan) {
            return $mail->hasTo('client@example.com') && $mail->plan->is($plan) && str_contains($mail->reviewUrl, '/review/');
        });
        $token = ReviewToken::query()->firstOrFail();
        $this->assertSame($plan->id, $token->grid_plan_id);
        $this->assertNull($token->post_id);
    }

    public function test_the_email_is_skipped_without_an_approval_address_and_the_move_still_happens(): void
    {
        $this->client->update(['approval_email' => null]);
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);

        app(TransitionGridPlanStatusAction::class)($plan, GridPlanStatus::WaitingClient, $this->owner());

        Mail::assertNothingQueued();
        $this->assertSame(GridPlanStatus::WaitingClient, $plan->fresh()?->status);
        $this->assertSame(0, ReviewToken::query()->count());
    }

    public function test_a_valid_link_shows_the_grid_without_anything_internal(): void
    {
        [$plan, $token] = $this->waitingWithToken();
        $tile = $plan->tiles()->firstOrFail();
        $tile->update(['team_note' => 'SECRET-TEAM-NOTE', 'caption_idea' => 'Hello autumn']);
        GridComment::query()->create(['grid_plan_id' => $plan->id, 'user_id' => $this->owner()->id, 'body' => 'SECRET-INTERNAL-COMMENT', 'internal_only' => true]);
        GridComment::query()->create(['grid_plan_id' => $plan->id, 'user_id' => $this->owner()->id, 'body' => 'Shared note', 'internal_only' => false]);

        $response = $this->get(route('review.show', $token))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('review/grid')
            ->where('grid.id', $plan->id)
            ->where('canDecide', true)
            ->has('grid.tiles', 3)
            ->where('grid.tiles.0.caption', 'Hello autumn')
            ->missing('grid.tiles.0.team_note')
            ->missing('grid.tiles.0.post')
            ->missing('grid.checklist')
            ->has('comments', 1));

        $this->assertIsolated($response->getContent());
    }

    public function test_approving_through_the_link(): void
    {
        [$plan, $token] = $this->waitingWithToken();

        $this->post(route('review.grid.decide', $token), ['decision' => 'approved', 'version' => $plan->version])
            ->assertRedirect(route('review.show', $token));

        $plan->refresh();
        $this->assertSame(GridPlanStatus::Approved, $plan->status);
        $this->assertNull($plan->approved_by);
        $this->assertNotNull($plan->approved_layout_hash);
    }

    public function test_requesting_changes_with_flagged_tiles_pins_comments_to_them(): void
    {
        [$plan, $token] = $this->waitingWithToken();
        [$first, $second] = $plan->tiles()->get()->all();
        $other = $this->planWithTiles(1);

        $this->post(route('review.grid.decide', $token), [
            'decision' => 'changes_requested',
            'version' => $plan->version,
            'comment' => 'Nearly there.',
            'tiles' => [$first->id => 'Brighter please', $second->id => null, $other->tiles()->firstOrFail()->id => 'Not mine'],
        ])->assertRedirect();

        $this->assertSame(GridPlanStatus::ChangesRequested, $plan->fresh()?->status);
        $comments = GridComment::query()->where('grid_plan_id', $plan->id)->orderBy('id')->get();
        $this->assertSame(['Nearly there.', 'Brighter please', 'Flagged for changes.'], $comments->pluck('body')->all());
        $this->assertSame([null, $first->id, $second->id], $comments->pluck('grid_tile_id')->all());
        $this->assertTrue($comments->every(fn (GridComment $comment) => ! $comment->internal_only && $comment->user_id === null));
    }

    public function test_requesting_changes_needs_a_note_or_a_flagged_tile(): void
    {
        [$plan, $token] = $this->waitingWithToken();

        $this->post(route('review.grid.decide', $token), ['decision' => 'changes_requested', 'version' => $plan->version])
            ->assertSessionHasErrors('comment');
        $this->assertSame(GridPlanStatus::WaitingClient, $plan->fresh()?->status);
    }

    public function test_a_grid_that_changed_while_the_client_looked_is_not_approved(): void
    {
        [$plan, $token] = $this->waitingWithToken();
        $seen = $plan->version;
        $plan->update(['version' => $seen + 1]);

        $this->from(route('review.show', $token))
            ->post(route('review.grid.decide', $token), ['decision' => 'approved', 'version' => $seen])
            ->assertRedirect(route('review.show', $token))
            ->assertSessionHasErrors(['version' => 'This grid changed while you were looking. Here’s the latest.']);

        $this->assertSame(GridPlanStatus::WaitingClient, $plan->fresh()?->status);
    }

    public function test_invalid_revoked_expired_cross_client_and_draft_tokens_404(): void
    {
        [$plan, $token, $model] = $this->waitingWithToken();

        $this->get(route('review.show', 'not-a-token'))->assertNotFound();

        $model->update(['revoked_at' => now()]);
        $this->get(route('review.show', $token))->assertNotFound();
        $this->post(route('review.grid.decide', $token), ['decision' => 'approved', 'version' => 1])->assertNotFound();

        $model->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->get(route('review.show', $token))->assertNotFound();

        // A token whose client doesn't own the grid.
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $crossToken = ReviewToken::query()->create(['token_hash' => hash('sha256', 'cross'), 'client_id' => $otherClient->id, 'grid_plan_id' => $plan->id, 'expires_at' => now()->addDay()]);
        $this->assertNotNull($crossToken);
        $this->get(route('review.show', 'cross'))->assertNotFound();

        // A grid pulled back to draft is no longer reachable.
        $model->update(['expires_at' => now()->addDay()]);
        $plan->update(['status' => GridPlanStatus::Draft]);
        $this->get(route('review.show', $token))->assertNotFound();
    }

    public function test_a_grid_token_never_decides_a_post_and_a_post_token_never_decides_a_grid(): void
    {
        [$plan, $token] = $this->waitingWithToken();

        $this->post(route('review.decide', $token), ['decision' => 'approved'])->assertNotFound();

        $postToken = (new CreateReviewTokenAction)($this->client)['token'];
        $this->post(route('review.grid.decide', $postToken), ['decision' => 'approved', 'version' => $plan->version])->assertNotFound();
        $this->assertSame(GridPlanStatus::WaitingClient, $plan->fresh()?->status);
    }

    public function test_the_portal_lists_only_sent_grids_of_the_contacts_own_client(): void
    {
        $sent = $this->planWithTiles(1, GridPlanStatus::WaitingClient);
        $draft = $this->planWithTiles(1);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $theirs = GridPlan::factory()->create(['client_id' => $otherClient->id, 'status' => GridPlanStatus::WaitingClient]);

        $this->actingAs($this->reviewer)->get(route('portal.grids.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/grids/index')
                ->has('grids', 1)
                ->where('grids.0.id', $sent->id));

        $this->actingAs($this->reviewer)->get(route('portal.grids.show', $draft))->assertNotFound();
        $this->actingAs($this->reviewer)->get(route('portal.grids.show', $theirs))->assertNotFound();
        $this->actingAs($this->reviewer)->post(route('portal.grids.decide', $theirs), ['decision' => 'approved', 'version' => 1])->assertNotFound();

        $this->actingAs($this->reviewer)->get(route('portal.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('portal.grids', ['visible' => 1, 'waiting' => 1])
                ->has('waitingGrids', 1));
    }

    public function test_the_portal_grid_page_is_isolated_and_the_contact_can_decide(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::WaitingClient);
        $plan->tiles()->firstOrFail()->update(['team_note' => 'SECRET-TEAM-NOTE']);
        GridComment::query()->create(['grid_plan_id' => $plan->id, 'user_id' => $this->owner()->id, 'body' => 'SECRET-INTERNAL-COMMENT', 'internal_only' => true]);

        $response = $this->actingAs($this->reviewer)->get(route('portal.grids.show', $plan))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/grids/show')
                ->where('can.decide', true)
                ->missing('grid.tiles.0.team_note')
                ->has('comments', 0));

        $this->assertIsolated($response->getContent());

        $this->actingAs($this->reviewer)->post(route('portal.grids.decide', $plan), ['decision' => 'changes_requested', 'version' => $plan->version, 'tiles' => [$plan->tiles()->firstOrFail()->id => 'Swap this']])
            ->assertRedirect(route('portal.grids.show', $plan));
        $this->assertSame(GridPlanStatus::ChangesRequested, $plan->fresh()?->status);
    }

    public function test_a_client_reviewer_opening_the_planner_is_sent_to_their_portal(): void
    {
        $plan = $this->planWithTiles(1, GridPlanStatus::WaitingClient);

        $this->actingAs($this->reviewer)->get(route('grids.edit', $plan))->assertRedirect(route('portal.grids.show', $plan));

        $plan->update(['status' => GridPlanStatus::Draft]);
        $this->actingAs($this->reviewer)->get(route('grids.edit', $plan))->assertRedirect(route('portal.grids.index'));

        $other = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => Client::factory()->for($this->org, 'organization')->create()->id]);
        $this->actingAs($other)->get(route('portal.grids.show', $plan))->assertNotFound();
    }

    /**
     * @return array{0: GridPlan, 1: string, 2: ReviewToken}
     */
    private function waitingWithToken(): array
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::WaitingClient);
        ['token' => $token, 'model' => $model] = (new CreateReviewTokenAction)($this->client, gridPlan: $plan);

        return [$plan, $token, $model];
    }

    private function assertIsolated(string|false $content): void
    {
        $content = (string) $content;

        foreach (['SECRET-TEAM-NOTE', 'SECRET-INTERNAL-COMMENT', 'retainer_amount', 'billing_email', 'cost_estimate', 'team_note', 'internal_only":true', 'checklist'] as $needle) {
            $this->assertStringNotContainsString($needle, $content, "Client page leaked {$needle}");
        }
    }
}
