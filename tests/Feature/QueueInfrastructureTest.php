<?php

namespace Tests\Feature;

use App\Contracts\OpsAlerter;
use App\Enums\Role;
use App\Jobs\BuildOrganizationDataExportJob;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishPostTargetJob;
use App\Jobs\SyncOrganizationEngagementJob;
use App\Listeners\AlertOnLongQueueWait;
use App\Mail\StaffInvitationMail;
use App\Models\Post;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use App\Support\Queues;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Events\LongWaitDetected;
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

    /**
     * Audit finding 12 step 4 — an export is work somebody is sitting and waiting for, and it used
     * to queue behind the half-hourly engagement sweep on `reports`.
     */
    public function test_exports_do_not_share_a_queue_or_a_worker_with_the_background_sweeps(): void
    {
        $this->assertSame(Queues::EXPORTS, (new BuildOrganizationDataExportJob(1))->queue);
        $this->assertSame(Queues::REPORTS, (new SyncOrganizationEngagementJob(1))->queue);

        $supervisors = config('horizon.defaults');

        $this->assertSame([Queues::EXPORTS], $supervisors['exports']['queue']);
        $this->assertNotContains(Queues::EXPORTS, $supervisors['background']['queue']);
        $this->assertGreaterThanOrEqual(1, $supervisors['exports']['minProcesses']);
    }

    /**
     * Audit finding 12 step 5 — the thresholds are useless unless something tells somebody.
     * Horizon's own notification needs a mail/Slack route registered on Horizon itself; this goes
     * through the alerter the rest of the app already uses.
     */
    public function test_a_long_queue_wait_raises_an_ops_alert(): void
    {
        $alerts = [];
        $this->app->instance(OpsAlerter::class, new class($alerts) implements OpsAlerter
        {
            /**
             * @param  array<int, string>  $alerts
             */
            public function __construct(public array &$alerts) {}

            public function alert(string $key, string $message, array $context = []): void
            {
                $this->alerts[] = $key.': '.$message;
            }
        });

        $this->assertArrayHasKey('redis:reports', config('horizon.waits'));
        $this->assertArrayHasKey('redis:exports', config('horizon.waits'));

        // Registered by listener discovery, then called directly: dispatching the event for real
        // also runs Horizon's own listener, which talks to Redis.
        $this->assertContains(
            AlertOnLongQueueWait::class.'@handle',
            Event::getRawListeners()[LongWaitDetected::class] ?? [],
        );

        app(AlertOnLongQueueWait::class)->handle(new LongWaitDetected('redis', Queues::REPORTS, 2400));

        $this->assertSame(['queue.long_wait:reports: Jobs on the reports queue have been waiting 2400 seconds.'], $alerts);
    }

    public function test_notifications_and_mail_have_their_own_queues(): void
    {
        $notification = new PostPublishFailed(Post::factory()->create());

        $this->assertSame(
            // Step W-2.4: a WhatsApp send is an HTTP round trip to Twilio, so it gets its own
            // queue rather than sitting in front of the bell notification carrying the same news.
            ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL, 'whatsapp' => Queues::WHATSAPP],
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

    public function test_every_horizon_queue_is_in_the_all_queues_list(): void
    {
        foreach (config('horizon.defaults') as $name => $supervisor) {
            foreach ($supervisor['queue'] as $queue) {
                $this->assertContains($queue, Queues::all(), "{$name} works {$queue}, which Queues::all() misses");
            }
        }

        $this->assertSame(Queues::PUBLISHING, Queues::all()[0]);
    }

    public function test_the_dev_queue_worker_listens_to_every_queue(): void
    {
        // phpunit.xml runs on the `sync` connection, which Horizon can't work, so `dev` must fall
        // back to a plain worker.
        $commands = collect(DevCommands::commands());
        $worker = $commands->firstWhere('name', 'queue');

        $this->assertNull($commands->firstWhere('name', 'horizon'));
        $this->assertNotNull($worker);
        $this->assertStringContainsString('queue:listen --queue='.implode(',', Queues::all()), $worker['command']);
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
