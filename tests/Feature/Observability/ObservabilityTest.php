<?php

namespace Tests\Feature\Observability;

use App\Contracts\ErrorTracker;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostTargetJob;
use App\Mail\OpsAlertMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Observability\TraceContext;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Step I-16.1 / M-1.1 — correlation ids, error tracking with trace tags, and on-call alerts for
 * failed publishing jobs and bad webhook signatures.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array{exception: Throwable, tags: array<string, string>}>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $captured = &$this->captured;
        $this->app->instance(ErrorTracker::class, new class($captured) implements ErrorTracker
        {
            /**
             * @param  list<array{exception: Throwable, tags: array<string, string>}>  $captured
             */
            public function __construct(private array &$captured) {}

            public function capture(Throwable $exception): void
            {
                $this->captured[] = ['exception' => $exception, 'tags' => TraceContext::tags()];
            }
        });

        config(['observability.alerts.mail_to' => 'oncall@alima.test']);
    }

    public function test_a_forced_publish_failure_reaches_error_tracking_with_post_and_correlation_ids_and_alerts(): void
    {
        Mail::fake();
        [$post, $target] = $this->pendingTarget();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->andThrow(new RuntimeException('Vendor exploded'));
        $this->app->instance(PublishAdapter::class, $adapter);

        // As if dispatched by a request: the request's correlation id travels with the job.
        Context::add(TraceContext::CORRELATION_ID, 'req-forced-failure-1');

        try {
            dispatch(new PublishPostTargetJob($target->id, 'attempt-1'));
            $this->fail('The job should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Vendor exploded', $e->getMessage());
        }

        $report = collect($this->captured)->first(fn (array $entry) => $entry['exception']->getMessage() === 'Vendor exploded');
        $this->assertNotNull($report, 'The failure was not sent to error tracking.');
        $this->assertSame((string) $post->id, $report['tags'][TraceContext::POST_ID]);
        $this->assertSame((string) $target->id, $report['tags'][TraceContext::POST_TARGET_ID]);
        $this->assertSame('req-forced-failure-1', $report['tags'][TraceContext::CORRELATION_ID]);
        $this->assertCount(1, collect($this->captured)->filter(fn (array $entry) => $entry['exception'] === $report['exception']));

        Mail::assertSent(OpsAlertMail::class, function (OpsAlertMail $mail) use ($post) {
            return $mail->alertKey === 'publishing.job_failed'
                && $mail->context[TraceContext::POST_ID] === $post->id
                && $mail->context[TraceContext::CORRELATION_ID] === 'req-forced-failure-1'
                && $mail->hasTo('oncall@alima.test');
        });

        $target->refresh();
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('req-forced-failure-1', $target->publish_correlation_id);
    }

    public function test_repeat_alerts_are_throttled_to_one_email(): void
    {
        Mail::fake();
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->andThrow(new RuntimeException('Vendor down'));
        $this->app->instance(PublishAdapter::class, $adapter);

        foreach (range(1, 3) as $i) {
            [, $target] = $this->pendingTarget();

            try {
                dispatch(new PublishPostTargetJob($target->id, 'attempt-1'));
            } catch (RuntimeException) {
                // expected
            }
        }

        Mail::assertSentCount(1);
    }

    public function test_a_job_dispatched_outside_a_request_gets_its_own_correlation_id(): void
    {
        [, $target] = $this->pendingTarget();
        $this->app->instance(PublishAdapter::class, $this->acceptingAdapter());
        Context::forget(TraceContext::CORRELATION_ID);

        dispatch(new PublishPostTargetJob($target->id, 'attempt-1'));

        $this->assertTrue(TraceContext::isValidId($target->refresh()->publish_correlation_id));
    }

    public function test_every_response_carries_a_correlation_id_and_reuses_a_well_formed_incoming_one(): void
    {
        $generated = $this->get('/')->assertOk()->headers->get('X-Request-Id');
        $this->assertTrue(TraceContext::isValidId($generated));

        $this->get('/', ['X-Request-Id' => 'lb-1234abcd'])->assertHeader('X-Request-Id', 'lb-1234abcd');

        $replaced = $this->get('/', ['X-Request-Id' => "bad id\ninjected"])->headers->get('X-Request-Id');
        $this->assertNotSame("bad id\ninjected", $replaced);
        $this->assertTrue(TraceContext::isValidId($replaced));
    }

    public function test_a_bad_webhook_signature_alerts_without_leaking_the_signature(): void
    {
        Mail::fake();
        [$post, $target, $org] = $this->pendingTarget(externalPostId: 'job-sig-1');
        $org->update(['upload_post_webhook_secret' => 'shh-secret']);

        $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-sig-1',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ], [
            'X-Upload-Post-Timestamp' => (string) time(),
            'X-Upload-Post-Signature' => 'sha256=forged-signature',
        ])->assertStatus(401);

        Mail::assertSent(OpsAlertMail::class, function (OpsAlertMail $mail) use ($post, $target) {
            $flat = json_encode($mail->context);

            return $mail->alertKey === 'webhooks.upload_post.signature_failed'
                && $mail->context['post_id'] === $post->id
                && $mail->context['post_target_id'] === $target->id
                && ! str_contains((string) $flat, 'forged-signature')
                && ! str_contains((string) $flat, 'shh-secret');
        });
        $this->assertSame(PostTargetStatus::Pending, $target->refresh()->status);
    }

    public function test_only_super_admins_can_view_pulse(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse(Gate::forUser($owner)->allows('viewPulse'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewPulse'));
    }

    /**
     * @return array{0: Post, 1: PostTarget, 2: Organization}
     */
    private function pendingTarget(?string $externalPostId = null): array
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'publish_attempt_id' => 'attempt-1',
            'external_post_id' => $externalPostId,
        ]);

        return [$post, $target, $org];
    }

    private function acceptingAdapter(): PublishAdapter
    {
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->andReturnUsing(
            fn (Post $p, $accounts) => $accounts->map(
                fn (SocialAccount $account) => new TargetResult(accountId: $account->id, ok: true, externalPostId: 'ext-'.$account->id),
            ),
        );

        return $adapter;
    }
}
