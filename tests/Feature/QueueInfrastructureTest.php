<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishPostTargetJob;
use App\Mail\StaffInvitationMail;
use App\Models\Post;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step I-2.8 — named queues and the Horizon dashboard gate.
 */
class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_jobs_run_on_their_own_queue(): void
    {
        $this->assertSame(Queues::PUBLISHING, (new PublishPostJob(1, 'dispatch'))->queue);
        $this->assertSame(Queues::PUBLISHING, (new PublishPostTargetJob(1, 'attempt'))->queue);
    }

    public function test_notifications_and_mail_have_their_own_queues(): void
    {
        $notification = new PostPublishFailed(Post::factory()->create());

        $this->assertSame(
            ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL],
            $notification->viaQueues(),
        );

        $mail = new StaffInvitationMail(StaffInvitation::factory()->create(), 'https://example.test/accept');
        $this->assertSame(Queues::MAIL, $mail->queue);
    }

    public function test_every_publishing_supervisor_is_isolated_from_ai_and_reports(): void
    {
        $supervisors = config('horizon.defaults');

        $this->assertSame(['publishing'], $supervisors['publishing']['queue']);

        foreach ($supervisors as $name => $supervisor) {
            if ($name !== 'publishing') {
                $this->assertNotContains('publishing', $supervisor['queue'], "{$name} also works the publishing queue");
            }

            // A worker must never be killed mid-job while Redis still thinks the job is running.
            $this->assertLessThan(config('queue.connections.redis.retry_after'), $supervisor['timeout'], $name);
        }
    }

    public function test_horizon_is_for_super_admins_only(): void
    {
        $this->get('/horizon')->assertForbidden();

        $owner = User::factory()->role(Role::Owner)->create();
        $this->actingAs($owner)->get('/horizon')->assertForbidden();

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get('/horizon')->assertOk();
    }
}
