<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\GridPlanStatusTransitionMap;
use App\Actions\Grids\TransitionGridPlanStatusAction;
use App\Enums\ApprovalMode;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Exceptions\Grids\InvalidGridTransitionException;
use App\Mail\ClientGridReviewRequestedMail;
use App\Models\GridComment;
use App\Models\GridPlan;
use App\Notifications\GridCommentNotification;
use App\Notifications\GridPlanStatusChanged;
use App\Services\Grids\GridPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.7 — the grid workflow: the transition map, moves over HTTP, the checklist gate, the
 * client-required rule and its Owner override, the approval stamp, notifications and comments.
 */
class GridWorkflowTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        Mail::fake();
        Notification::fake();
        $this->client->update(['default_approval_mode' => ApprovalMode::ClientRequired]);
    }

    public function test_every_status_has_an_entry_in_the_map(): void
    {
        foreach (GridPlanStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, GridPlanStatusTransitionMap::MAP, $status->value);

            foreach (GridPlanStatusTransitionMap::MAP[$status->value] as $to) {
                $this->assertNotNull(GridPlanStatus::tryFrom($to));
            }
        }
    }

    public function test_a_grid_moves_through_review_to_the_client_and_is_approved(): void
    {
        $plan = $this->planWithTiles(3);

        $this->actingAs($this->strategist())->post(route('grids.transition', $plan), ['to' => 'internal_review', 'note' => 'Have a look'])
            ->assertRedirect(route('grids.edit', $plan));
        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'waiting_client'])
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame(GridPlanStatus::WaitingClient, $plan->status);
        $this->assertSame(['draft', 'internal_review'], $plan->activityLogs()->orderBy('id')->get()->map(fn ($log) => $log->from_status?->value)->all());
        $this->assertNotNull($plan->activityLogs()->latest('id')->first()?->review_token_id);
        Mail::assertQueued(ClientGridReviewRequestedMail::class, fn ($mail) => $mail->hasTo('client@example.com'));

        Notification::assertSentTo($this->designer(), GridPlanStatusChanged::class);
        Notification::assertNotSentTo($this->strategist(), GridPlanStatusChanged::class);
        Notification::assertSentTo($this->reviewer, GridPlanStatusChanged::class);
    }

    public function test_rejected_moves_over_http(): void
    {
        $plan = $this->planWithTiles(1);

        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'approved'])->assertSessionHasErrors('to');
        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'waiting_client'])->assertSessionHasErrors('to');
        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'nonsense'])->assertSessionHasErrors('to');
        $this->actingAs($this->viewer())->post(route('grids.transition', $plan), ['to' => 'internal_review'])->assertForbidden();

        $this->assertSame(GridPlanStatus::Draft, $plan->fresh()?->status);
    }

    public function test_the_hard_checklist_must_pass_before_the_client_sees_it_or_it_is_approved(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);
        $this->addTile($plan, GridTileKind::Placeholder);

        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'waiting_client'])
            ->assertSessionHasErrors(['to' => 'Finish the checklist first: every tile has its media.']);
        $this->assertSame(GridPlanStatus::InternalReview, $plan->fresh()?->status);
        Mail::assertNothingQueued();

        // Soft warnings (a 4:5 tile cropped on the grid) never block.
        $plan->tiles()->where('kind', 'placeholder')->delete();
        $plan->tiles()->first()?->update(['format' => '4:5']);
        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'waiting_client'])->assertSessionHasNoErrors();
    }

    public function test_client_required_blocks_internal_approval_but_an_owner_can_override_with_a_reason(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);

        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'approved'])
            ->assertSessionHasErrors(['to' => 'This grid needs the client’s approval. Send it to the client, or an Owner can override.']);

        $this->actingAs($this->strategist())->post(route('grids.override-approval', $plan), ['reason' => 'Client approved on the call'])->assertForbidden();
        $this->actingAs($this->owner())->post(route('grids.override-approval', $plan), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner())->post(route('grids.override-approval', $plan), ['reason' => 'Client approved on the call'])->assertRedirect();

        $plan->refresh();
        $this->assertSame(GridPlanStatus::Approved, $plan->status);
        $this->assertSame('Approval override: Client approved on the call', $plan->activityLogs()->latest('id')->first()?->note);
    }

    public function test_internal_only_clients_can_be_approved_by_the_team(): void
    {
        $this->client->update(['default_approval_mode' => ApprovalMode::InternalOnly]);
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);

        $this->actingAs($this->designer())->post(route('grids.transition', $plan), ['to' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(GridPlanStatus::Approved, $plan->fresh()?->status);
    }

    public function test_approval_stamps_who_when_and_the_layout_hash_and_leaving_approved_clears_it(): void
    {
        $this->client->update(['default_approval_mode' => ApprovalMode::InternalOnly]);
        $plan = $this->planWithTiles(3, GridPlanStatus::InternalReview);

        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'approved']);

        $plan->refresh();
        $this->assertSame($this->owner()->id, $plan->approved_by);
        $this->assertNotNull($plan->approved_at);
        $this->assertSame((new GridPlanService)->layoutHash(GridPlan::query()->findOrFail($plan->id)), $plan->approved_layout_hash);

        $this->actingAs($this->owner())->post(route('grids.transition', $plan), ['to' => 'draft']);
        $plan->refresh();
        $this->assertNull($plan->approved_at);
        $this->assertNull($plan->approved_layout_hash);
    }

    public function test_the_client_deciding_notifies_the_team(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::WaitingClient);

        $this->actingAs($this->reviewer)->post(route('portal.grids.decide', $plan), ['decision' => 'approved', 'version' => $plan->version])->assertRedirect();

        Notification::assertSentTo([$this->owner(), $this->strategist(), $this->designer(), $this->viewer()], GridPlanStatusChanged::class);
    }

    public function test_comments_are_tile_pinned_mention_aware_and_never_internal_for_a_reviewer(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::WaitingClient);
        $tile = $plan->tiles()->firstOrFail();

        $this->actingAs($this->designer())->post(route('grids.comments.store', $plan), [
            'body' => 'Can we swap this? @['.$this->strategist()->name.']('.$this->strategist()->id.')',
            'internal_only' => true,
            'grid_tile_id' => $tile->id,
        ])->assertRedirect();

        $comment = GridComment::query()->latest('id')->firstOrFail();
        $this->assertTrue($comment->internal_only);
        $this->assertSame($tile->id, $comment->grid_tile_id);
        $this->assertSame([$this->strategist()->id], $comment->mentionedUsers()->pluck('users.id')->all());
        Notification::assertSentTo($this->strategist(), GridCommentNotification::class);
        Notification::assertNotSentTo($this->reviewer, GridCommentNotification::class);

        $this->actingAs($this->reviewer)->post(route('portal.grids.comments.store', $plan), ['body' => 'Love it', 'internal_only' => true]);
        $this->assertFalse(GridComment::query()->latest('id')->firstOrFail()->internal_only);
        Notification::assertSentTo($this->owner(), GridCommentNotification::class);

        // A tile from another grid can't be pinned.
        $other = $this->planWithTiles(1);
        $this->actingAs($this->owner())->post(route('grids.comments.store', $plan), ['body' => 'x', 'grid_tile_id' => $other->tiles()->firstOrFail()->id])
            ->assertSessionHasErrors('grid_tile_id');
    }

    public function test_a_concurrent_move_is_refused_with_a_toast(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);
        $stale = GridPlan::query()->findOrFail($plan->id);

        GridPlan::query()->whereKey($plan->id)->update(['status' => GridPlanStatus::Draft->value]);

        $this->expectException(InvalidGridTransitionException::class);
        app(TransitionGridPlanStatusAction::class)($stale, GridPlanStatus::WaitingClient, $this->owner());
    }
}
