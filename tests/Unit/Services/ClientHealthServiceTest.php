<?php

namespace Tests\Unit\Services;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\RecomputeClientHealthJob;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ClientHealthService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ClientHealthService
    {
        return new ClientHealthService;
    }

    private function healthyClientState(): array
    {
        return [
            'status' => ClientStatus::Active,
            'owner_user_id' => User::factory()->create()->id,
            'start_date' => now()->subMonth(),
            'retainer_amount' => 1000,
            'billing_cycle' => BillingCycle::Monthly,
        ];
    }

    public function test_a_fully_healthy_active_client_is_green(): void
    {
        $client = Client::factory()->create($this->healthyClientState());

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Green, $result['status']);
        $this->assertSame('Active client in good standing.', $result['reason']);
    }

    public function test_archived_client_is_green(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Archived]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Green, $result['status']);
        $this->assertSame('Client is archived; no active engagement risk.', $result['reason']);
    }

    public function test_offboarding_client_is_red(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Offboarding]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Client is offboarding.', $result['reason']);
    }

    public function test_active_client_without_owner_is_red(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'owner_user_id' => null]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Active client has no assigned owner.', $result['reason']);
    }

    public function test_active_client_without_any_billing_setup_is_red(): void
    {
        $client = Client::factory()->create([
            ...$this->healthyClientState(),
            'retainer_amount' => null,
            'billing_cycle' => null,
        ]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Active client has no billing setup (missing retainer amount and billing cycle).', $result['reason']);
    }

    public function test_paused_client_is_amber(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Paused]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Client is paused.', $result['reason']);
    }

    public function test_active_client_without_start_date_is_amber(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'start_date' => null]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Active client has no start date recorded.', $result['reason']);
    }

    public function test_active_client_with_partial_billing_setup_is_amber(): void
    {
        $client = Client::factory()->create([
            ...$this->healthyClientState(),
            'retainer_amount' => 1000,
            'billing_cycle' => null,
        ]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Client billing setup is incomplete (retainer amount or billing cycle missing).', $result['reason']);
    }

    public function test_client_with_a_token_expired_social_account_is_red(): void
    {
        $client = Client::factory()->create($this->healthyClientState());

        SocialAccount::factory()->create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'connection_status' => ConnectionStatus::TokenExpired,
        ]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('A connected social account has an expired/revoked token.', $result['reason']);
    }

    public function test_client_with_only_connected_social_accounts_is_unaffected(): void
    {
        $client = Client::factory()->create($this->healthyClientState());

        SocialAccount::factory()->create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'connection_status' => ConnectionStatus::Connected,
        ]);

        // Step M-3.4: with an account, an empty calendar is a signal — plan something.
        $this->plannedPost($client, now()->addDays(2));

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Green, $result['status']);
    }

    // --- Step M-3.4 / I-6.1: health v2 signals -------------------------------------------------

    private function healthyClient(array $attributes = []): Client
    {
        $client = Client::factory()->create([...$this->healthyClientState(), ...$attributes]);
        BrandBrain::factory()->for($client)->create();

        return $client;
    }

    private function account(Client $client): SocialAccount
    {
        return SocialAccount::factory()->create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'connection_status' => ConnectionStatus::Connected,
        ]);
    }

    private function plannedPost(Client $client, CarbonInterface $at, PostStatus $status = PostStatus::Scheduled): Post
    {
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => $status]);
        $post->targets()->create([
            'social_account_id' => $client->socialAccounts()->value('id') ?? $this->account($client)->id,
            'status' => PostTargetStatus::Pending,
            'scheduled_at_utc' => $at,
        ]);

        return $post;
    }

    private function transitioned(Post $post, PostStatus $to, CarbonInterface $at): void
    {
        $log = PostActivityLog::factory()->create(['post_id' => $post->id, 'from_status' => PostStatus::Publishing, 'to_status' => $to]);
        $log->forceFill(['created_at' => $at])->save();
    }

    /**
     * @return list<string>
     */
    private function keys(Client $client): array
    {
        return array_column($this->service()->compute($client->fresh() ?? $client)['reasons'], 'key');
    }

    public function test_a_recent_failed_publish_is_amber_and_three_are_red(): void
    {
        $client = $this->healthyClient();
        $this->plannedPost($client, now()->addDay());
        $failed = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Failed]);
        $this->transitioned($failed, PostStatus::Failed, now()->subDays(2));

        $result = $this->service()->compute($client);
        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('1 post failed to publish in the last 7 days — retry or fix it.', $result['reason']);

        foreach (range(1, 2) as $i) {
            $more = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::PartiallyPublished]);
            $this->transitioned($more, PostStatus::PartiallyPublished, now()->subDay());
        }

        $result = $this->service()->compute($client);
        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertStringStartsWith('3 posts failed to publish', $result['reason']);
    }

    public function test_old_or_already_fixed_failures_dont_count(): void
    {
        $client = $this->healthyClient();
        $this->plannedPost($client, now()->addDay());
        $old = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Failed]);
        $this->transitioned($old, PostStatus::Failed, now()->subDays(9));
        $retried = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Published]);
        $this->transitioned($retried, PostStatus::Failed, now()->subDay());

        $this->assertNotContains('failed_publishes', $this->keys($client));
    }

    public function test_approvals_waiting_past_the_threshold_are_flagged_and_red_when_due_soon(): void
    {
        $client = $this->healthyClient();
        $waiting = $this->plannedPost($client, now()->addDays(4), PostStatus::WaitingClient);
        $this->transitioned($waiting, PostStatus::WaitingClient, now()->subHours(30));

        $this->assertNotContains('overdue_approvals', $this->keys($client));

        $this->transitioned($waiting, PostStatus::WaitingClient, now()->subHours(50));
        PostActivityLog::query()->where('post_id', $waiting->id)->where('created_at', '>', now()->subHours(40))->delete();
        $result = $this->service()->compute($client);
        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('1 post waiting on the client for over 2 days.', $result['reason']);

        $waiting->targets()->update(['scheduled_at_utc' => now()->addHours(6)]);
        $result = $this->service()->compute($client);
        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertStringContainsString('due to go out within a day', $result['reason']);
    }

    public function test_overdue_invoices_are_an_owner_only_reason_without_amounts(): void
    {
        $client = $this->healthyClient();
        $this->plannedPost($client, now()->addDay());
        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Sent, 'amount' => 999.5, 'due_date' => now()->subDays(10)->toDateString()]);
        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Draft, 'due_date' => now()->subDays(90)->toDateString()]);
        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Paid, 'due_date' => now()->subDays(90)->toDateString()]);

        $result = $this->service()->compute($client);
        $reason = collect($result['reasons'])->firstWhere('key', 'overdue_invoices');

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertTrue($reason['owner_only']);
        $this->assertSame('1 invoice is overdue (oldest by 10 days).', $reason['label']);
        $this->assertStringNotContainsString('999', $reason['label']);

        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->subDays(45)->toDateString()]);
        $this->assertSame(ClientHealth::Red, $this->service()->compute($client)['status']);
    }

    public function test_owner_only_reasons_are_hidden_from_everyone_else(): void
    {
        $client = $this->healthyClient();
        $this->plannedPost($client, now()->addDay());
        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->subDays(10)->toDateString()]);
        app(RecomputeClientHealthAction::class)($client);
        $client->refresh();

        $owner = User::factory()->create(['org_id' => $client->org_id, 'role' => Role::Owner]);
        $designer = User::factory()->create(['org_id' => $client->org_id, 'role' => Role::Designer]);

        $this->assertSame('1 invoice is overdue (oldest by 10 days).', $client->healthFor($owner)['reason']);
        $this->assertContains('overdue_invoices', array_column($client->healthFor($owner)['reasons'], 'key'));

        $designerView = $client->healthFor($designer);
        $this->assertSame(ClientHealth::Amber, $designerView['status']);
        $this->assertSame('Needs attention — an Owner has the details.', $designerView['reason']);
        $this->assertNotContains('overdue_invoices', array_column($designerView['reasons'], 'key'));
        $this->assertArrayNotHasKey('owner_only', $designerView['reasons'][0] ?? []);
    }

    public function test_cadence_below_the_weekly_target_is_amber(): void
    {
        $client = $this->healthyClient(['target_posts_per_week' => 3, 'start_date' => now()->subMonths(3)]);
        $account = $this->account($client);
        $this->plannedPost($client, now()->addDay());

        foreach (range(1, 4) as $week) {
            $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Published]);
            $post->targets()->create(['social_account_id' => $account->id, 'status' => PostTargetStatus::Published, 'published_at' => now()->subWeeks($week)->addDay()]);
        }

        $result = $this->service()->compute($client);
        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Posting 1 a week against a target of 3.', $result['reason']);

        $client->update(['target_posts_per_week' => 1]);
        $this->assertNotContains('cadence', $this->keys($client));

        $client->update(['target_posts_per_week' => null]);
        $this->assertNotContains('cadence', $this->keys($client));
    }

    public function test_an_empty_calendar_is_amber_for_an_active_client_with_accounts(): void
    {
        $client = $this->healthyClient();
        $this->assertNotContains('calendar_gap', $this->keys($client)); // no accounts yet

        $this->account($client);
        $result = $this->service()->compute($client->fresh());
        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Nothing planned for the next 7 days.', $result['reason']);

        $this->plannedPost($client, now()->addDays(10));
        $this->assertContains('calendar_gap', $this->keys($client));
        $this->plannedPost($client, now()->addDays(3), PostStatus::Draft);
        $this->assertNotContains('calendar_gap', $this->keys($client));

        $client->update(['status' => ClientStatus::Paused]);
        $this->assertNotContains('calendar_gap', $this->keys($client));
    }

    public function test_brand_brain_gaps_are_listed_but_never_change_the_health(): void
    {
        $client = Client::factory()->create($this->healthyClientState());

        $result = $this->service()->compute($client);
        $reason = collect($result['reasons'])->firstWhere('key', 'brand_brain');

        $this->assertSame(ClientHealth::Green, $result['status']);
        $this->assertSame('Active client in good standing.', $result['reason']);
        $this->assertSame('Brand Brain is missing tone of voice, audience and content pillars — AI drafts will be generic.', $reason['label']);
        $this->assertSame('green', $reason['tone']);

        BrandBrain::factory()->for($client)->create(['content_pillars' => []]);
        $this->assertSame('Brand Brain is missing content pillars — AI drafts will be generic.', collect($this->service()->compute($client->fresh())['reasons'])->firstWhere('key', 'brand_brain')['label']);
    }

    public function test_reasons_are_ordered_by_weight_and_the_heaviest_is_the_headline(): void
    {
        $client = $this->healthyClient(['owner_user_id' => null, 'start_date' => null]);
        $this->account($client);
        $failed = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Failed]);
        $this->transitioned($failed, PostStatus::Failed, now()->subDay());

        $result = $this->service()->compute($client->fresh());

        $this->assertSame(['no_owner', 'failed_publishes', 'calendar_gap', 'no_start_date'], array_column($result['reasons'], 'key'));
        $weights = array_column($result['reasons'], 'weight');
        $sorted = $weights;
        rsort($sorted);
        $this->assertSame($sorted, $weights);
        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Active client has no assigned owner.', $result['reason']);
    }

    public function test_an_archived_client_has_no_reasons(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Archived, 'owner_user_id' => null]);

        $this->assertSame([], $this->service()->compute($client)['reasons']);
    }

    public function test_recompute_stores_the_reasons_and_history_only_tracks_headline_changes(): void
    {
        $client = $this->healthyClient();
        $this->account($client);
        $history = fn () => $client->healthHistory()->count();
        app(RecomputeClientHealthAction::class)($client);
        $before = $history();

        $this->assertSame('calendar_gap', $client->fresh()?->health_reasons[0]['key']);

        // Same headline, different list: reasons update, no new history row.
        Invoice::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->subDays(3)->toDateString()]);
        app(RecomputeClientHealthAction::class)($client->fresh());

        $this->assertContains('overdue_invoices', array_column($client->fresh()?->health_reasons ?? [], 'key'));
        $this->assertSame($before + 1, $history()); // the invoice reason (58) outweighs the gap (40)
    }

    public function test_a_post_status_change_queues_a_health_recompute(): void
    {
        $client = $this->healthyClient();
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Draft]);
        Queue::fake();

        $post->update(['status' => PostStatus::InternalReview]);
        $post->update(['master_caption' => 'Only the caption changed']);

        Queue::assertPushed(RecomputeClientHealthJob::class, 1);
        Queue::assertPushed(RecomputeClientHealthJob::class, fn ($job) => $job->clientId === $client->id);
    }
}
