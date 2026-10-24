<?php

namespace Tests\Feature\Inbox;

use App\Enums\Role;
use App\Jobs\SendOverdueInboxRemindersJob;
use App\Notifications\InboxReplyOverdue;
use App\Services\Inbox\InboxSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\TestCase;

class InboxSlaTest extends TestCase
{
    use BuildsInboxFixtures, RefreshDatabase;

    public function test_a_thread_past_the_sla_with_no_reply_is_overdue(): void
    {
        $org = $this->inboxOrg();
        $org->forceFill(['inbox_sla_hours' => 4])->save();
        $conversation = $this->conversation($org, attributes: [
            'is_reply_needed' => true,
            'last_inbound_at' => now()->subHours(6),
            'first_response_at' => null,
        ]);

        $this->assertTrue(app(InboxSlaService::class)->isOverdue($conversation));
    }

    public function test_an_answered_thread_is_not_overdue(): void
    {
        $org = $this->inboxOrg();
        $org->forceFill(['inbox_sla_hours' => 4])->save();
        $conversation = $this->conversation($org, attributes: [
            'is_reply_needed' => false,
            'last_inbound_at' => now()->subHours(6),
            'first_response_at' => now()->subHour(),
        ]);

        $this->assertFalse(app(InboxSlaService::class)->isOverdue($conversation));
    }

    public function test_the_reminder_job_notifies_the_assignee_once(): void
    {
        Notification::fake();

        $org = $this->inboxOrg();
        $org->forceFill(['inbox_sla_hours' => 4])->save();
        $assignee = $this->staff($org, Role::Designer);
        $conversation = $this->conversation($org, attributes: [
            'is_reply_needed' => true,
            'last_inbound_at' => now()->subHours(6),
            'assigned_to' => $assignee->id,
        ]);

        (new SendOverdueInboxRemindersJob)->handle(app(InboxSlaService::class));

        Notification::assertSentTo($assignee, InboxReplyOverdue::class);
        $this->assertNotNull($conversation->fresh()->reminder_sent_at);

        // A second same-day run doesn't re-notify (throttled by reminder_sent_at).
        Notification::fake();
        (new SendOverdueInboxRemindersJob)->handle(app(InboxSlaService::class));
        Notification::assertNothingSent();
    }

    public function test_the_reminder_falls_back_to_the_studio_when_unassigned(): void
    {
        Notification::fake();

        $org = $this->inboxOrg();
        $org->forceFill(['inbox_sla_hours' => 4])->save();
        $owner = $this->staff($org, Role::Owner);
        $this->conversation($org, attributes: [
            'is_reply_needed' => true,
            'last_inbound_at' => now()->subHours(6),
            'assigned_to' => null,
        ]);

        (new SendOverdueInboxRemindersJob)->handle(app(InboxSlaService::class));

        Notification::assertSentTo($owner, InboxReplyOverdue::class);
    }
}
