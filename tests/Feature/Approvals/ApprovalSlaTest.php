<?php

namespace Tests\Feature\Approvals;

use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Actions\Portal\GetPortalDashboardItemsAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\SendOverdueApprovalRemindersJob;
use App\Mail\ClientApprovalReminderMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use App\Services\PostApprovalSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-6.5 (Phase 9) — approval SLA & reminders.
 */
class ApprovalSlaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmPassword();

        $this->org = Organization::factory()->create(['approval_sla_hours' => 48]);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => null]);
    }

    /** A post waiting on the client, that entered that status `$hoursAgo` ago. */
    private function waitingPost(int $hoursAgo): Post
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);
        PostActivityLog::factory()->create([
            'post_id' => $post->id,
            'to_status' => PostStatus::WaitingClient,
            'created_at' => now()->subHours($hoursAgo),
        ]);

        return $post;
    }

    public function test_sla_defaults_and_persists_and_clears(): void
    {
        $this->assertSame(48, Organization::factory()->create()->fresh()->approval_sla_hours, 'Defaults to 48h.');

        $this->actingAs($this->owner)
            ->put(route('organization.update'), [
                'default_timezone' => 'Europe/London',
                'default_currency' => 'GBP',
                'approval_sla_hours' => 24,
            ])->assertSessionHasNoErrors();
        $this->assertSame(24, $this->org->refresh()->approval_sla_hours);

        $this->actingAs($this->owner)
            ->put(route('organization.update'), [
                'default_timezone' => 'Europe/London',
                'default_currency' => 'GBP',
                'approval_sla_hours' => '',
            ])->assertSessionHasNoErrors();
        $this->assertNull($this->org->refresh()->approval_sla_hours);
    }

    public function test_is_overdue_respects_the_threshold(): void
    {
        $sla = app(PostApprovalSlaService::class);

        $this->assertTrue($sla->isOverdue($this->waitingPost(50)), 'Past the 48h SLA.');
        $this->assertFalse($sla->isOverdue($this->waitingPost(40)), 'Still within the SLA.');

        $this->org->update(['approval_sla_hours' => null]);
        $this->assertFalse($sla->isOverdue($this->waitingPost(500)->refresh()), 'No SLA = never overdue.');
    }

    public function test_home_marks_overdue_approvals(): void
    {
        $overdue = $this->waitingPost(60);
        $fresh = $this->waitingPost(2);

        $items = app(GetNeedsAttentionItemsAction::class)($this->owner);

        $rows = collect($items['waitingApprovals']['items'])->keyBy('post_id');
        $this->assertTrue($rows[$overdue->id]['overdue']);
        $this->assertFalse($rows[$fresh->id]['overdue']);
        $this->assertSame(1, $items['waitingApprovals']['overdue']);
    }

    public function test_portal_dashboard_marks_overdue_approvals(): void
    {
        $overdue = $this->waitingPost(60);
        $this->waitingPost(2);
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);

        $items = app(GetPortalDashboardItemsAction::class)($reviewer);

        $rows = collect($items['waitingApprovals'])->keyBy('post_id');
        $this->assertTrue($rows[$overdue->id]['overdue']);
    }

    public function test_reminder_job_sends_once_per_day_per_overdue_post(): void
    {
        Mail::fake();
        Notification::fake();
        ClientContact::factory()->forClient($this->client)->create(['email' => 'client@acme.test', 'receives_review_emails' => true]);

        $overdue = $this->waitingPost(60);
        $this->waitingPost(2); // within SLA — never reminded.

        app(SendOverdueApprovalRemindersJob::class)->handle(app(PostApprovalSlaService::class), app(CreateReviewTokenAction::class));

        Notification::assertSentTo($this->owner, ApprovalOverdue::class);
        Mail::assertQueued(ClientApprovalReminderMail::class, 1);
        $this->assertNotNull($overdue->refresh()->last_reminded_at);

        // A second run the same day sends nothing more.
        Mail::fake();
        app(SendOverdueApprovalRemindersJob::class)->handle(app(PostApprovalSlaService::class), app(CreateReviewTokenAction::class));
        Mail::assertNothingQueued();
    }

    public function test_no_reminders_without_an_sla(): void
    {
        Mail::fake();
        Notification::fake();
        $this->org->update(['approval_sla_hours' => null]);
        ClientContact::factory()->forClient($this->client)->create(['receives_review_emails' => true]);
        $this->waitingPost(500);

        app(SendOverdueApprovalRemindersJob::class)->handle(app(PostApprovalSlaService::class), app(CreateReviewTokenAction::class));

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
