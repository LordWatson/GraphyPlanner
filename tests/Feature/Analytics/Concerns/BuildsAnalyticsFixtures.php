<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase 17 — one hand-built studio ("Brightside", Europe/London, GBP) with a known set of posts,
 * transitions, invoices and AI usage, plus a second org ("Elsewhere") whose data must never leak.
 * "Now" is frozen at 2026-10-17 12:00 UTC (13:00 BST). Expected figures for the default 30-day
 * range are documented next to each fixture.
 */
trait BuildsAnalyticsFixtures
{
    protected Organization $org;

    protected Organization $otherOrg;

    protected User $owner;

    protected User $strategist;

    protected User $designer;

    protected User $viewer;

    protected User $reviewer;

    protected Client $acme;

    protected Client $bolt;

    protected Client $crest;

    protected Client $otherClient;

    protected Campaign $launch;

    /** @var array<string, Post> */
    protected array $posts = [];

    protected function buildAnalyticsFixtures(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->org = Organization::factory()->create([
            'name' => 'Brightside Studio',
            'default_timezone' => 'Europe/London',
            'default_currency' => 'GBP',
            'ai_monthly_image_quota' => 200,
        ]);

        $this->owner = $this->staff('Olive Owner', Role::Owner);
        $this->strategist = $this->staff('Sam Strategist', Role::Strategist);
        $this->designer = $this->staff('Dee Designer', Role::Designer);
        $this->viewer = $this->staff('Vic Viewer', Role::Viewer);

        // Acme: owner, start date, monthly retainer — amber under health v2 (Step M-3.4) because its
        // post failed this week. Bolt: paused → amber. Crest: active, no owner → red, quarterly retainer. An archived client is left out everywhere.
        $this->acme = Client::factory()->for($this->org, 'organization')->create([
            'name' => 'Acme', 'owner_user_id' => $this->owner->id, 'retainer_amount' => 1200, 'billing_cycle' => BillingCycle::Monthly,
            'created_at' => now()->subDays(100),
        ]);
        $this->bolt = Client::factory()->for($this->org, 'organization')->create([
            'name' => 'Bolt', 'status' => ClientStatus::Paused, 'owner_user_id' => $this->owner->id, 'created_at' => now()->subDays(10),
        ]);
        $this->crest = Client::factory()->for($this->org, 'organization')->create([
            'name' => 'Crest', 'owner_user_id' => null, 'retainer_amount' => 2400, 'billing_cycle' => BillingCycle::Quarterly,
            'created_at' => now()->subDays(100),
        ]);
        Client::factory()->for($this->org, 'organization')->create(['name' => 'Archived Co', 'status' => ClientStatus::Archived, 'created_at' => now()->subDays(100)]);

        $this->reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create([
            'name' => 'Rae Reviewer', 'client_id' => $this->acme->id,
        ]);

        $instagram = $this->account($this->acme, Platform::Instagram);
        $linkedin = $this->account($this->acme, Platform::LinkedIn);
        $tiktok = $this->account($this->bolt, Platform::TikTok);
        $crestInstagram = $this->account($this->crest, Platform::Instagram);

        $this->launch = Campaign::factory()->create(['org_id' => $this->org->id, 'client_id' => $this->acme->id, 'name' => 'Autumn launch']);

        // p1 — Acme/launch/Instagram: internal review 4h, client approved after 24h, published.
        $p1 = $this->makePost('p1', $this->acme, PostStatus::Published, $this->strategist, 5, $instagram, $this->launch);
        $this->transition($p1, PostStatus::Draft, PostStatus::InternalReview, $this->strategist, '2026-10-12 10:00:00');
        $this->transition($p1, PostStatus::InternalReview, PostStatus::WaitingClient, $this->strategist, '2026-10-12 14:00:00');
        $this->transition($p1, PostStatus::WaitingClient, PostStatus::Approved, null, '2026-10-13 14:00:00');
        $this->transition($p1, PostStatus::Approved, PostStatus::Scheduled, $this->strategist, '2026-10-13 15:00:00');
        $this->transition($p1, PostStatus::Scheduled, PostStatus::Publishing, null, '2026-10-14 08:59:00');
        $this->transition($p1, PostStatus::Publishing, PostStatus::Published, null, '2026-10-14 09:00:00');

        // p2 — Acme/launch/LinkedIn: reviewer asked for changes after 6h, approved 30h after the
        // resend (not first-time), then failed to publish.
        $p2 = $this->makePost('p2', $this->acme, PostStatus::Failed, $this->designer, 4, $linkedin, $this->launch);
        $this->transition($p2, PostStatus::Draft, PostStatus::WaitingClient, $this->strategist, '2026-10-13 10:00:00');
        $this->transition($p2, PostStatus::WaitingClient, PostStatus::ChangesRequested, $this->reviewer, '2026-10-13 16:00:00');
        $this->transition($p2, PostStatus::ChangesRequested, PostStatus::WaitingClient, $this->designer, '2026-10-14 10:00:00');
        $this->transition($p2, PostStatus::WaitingClient, PostStatus::Approved, null, '2026-10-15 16:00:00');
        $this->transition($p2, PostStatus::Approved, PostStatus::Publishing, null, '2026-10-16 09:00:00');
        $this->transition($p2, PostStatus::Publishing, PostStatus::Failed, null, '2026-10-16 09:01:00');

        // p3 — Bolt/TikTok: waiting on the client since 15 Oct 08:00 UTC (the oldest, 52h).
        $p3 = $this->makePost('p3', $this->bolt, PostStatus::WaitingClient, $this->strategist, 2, $tiktok);
        $this->transition($p3, PostStatus::Draft, PostStatus::WaitingClient, $this->strategist, '2026-10-15 08:00:00');

        // p4 — Acme/Instagram: waiting since yesterday; an AI-image note row must not count.
        $p4 = $this->makePost('p4', $this->acme, PostStatus::WaitingClient, $this->owner, 1, $instagram);
        $this->transition($p4, PostStatus::Draft, PostStatus::WaitingClient, $this->owner, '2026-10-16 12:00:00');
        $this->transition($p4, PostStatus::WaitingClient, PostStatus::WaitingClient, $this->owner, '2026-10-16 13:00:00', 'Added 2 AI images');

        // p5 — Acme: scheduled long ago (outside the range), still scheduled now.
        $p5 = $this->makePost('p5', $this->acme, PostStatus::Scheduled, $this->strategist, 40, $instagram);
        $this->transition($p5, PostStatus::Approved, PostStatus::Scheduled, $this->strategist, '2026-09-07 10:00:00');

        // p6 — Crest/Instagram: published 23:30 UTC on 16 Oct = 00:30 BST on 17 Oct.
        $p6 = $this->makePost('p6', $this->crest, PostStatus::Published, $this->strategist, 1, $crestInstagram);
        $this->transition($p6, PostStatus::Publishing, PostStatus::Published, null, '2026-10-16 23:30:00');

        // Billing — GBP (default) and AED; draft/void never count.
        $this->makeInvoice($this->acme, InvoiceStatus::Sent, 1000, 'GBP', '2026-10-07', dueDate: '2026-11-07', sentAt: '2026-10-07 09:00:00');
        $this->makeInvoice($this->acme, InvoiceStatus::Paid, 500, 'GBP', '2026-09-27', dueDate: '2026-10-27', sentAt: '2026-09-27 09:00:00', paidAt: '2026-10-12 09:00:00');
        $this->makeInvoice($this->bolt, InvoiceStatus::Sent, 2000, 'AED', '2026-10-02', dueDate: '2026-10-16', sentAt: '2026-10-02 09:00:00');
        $this->makeInvoice($this->acme, InvoiceStatus::Sent, 300, 'GBP', '2026-08-18', dueDate: '2026-12-01', sentAt: '2026-08-18 09:00:00');
        $this->makeInvoice($this->acme, InvoiceStatus::Draft, 999, 'GBP', '2026-10-10');
        $this->makeInvoice($this->acme, InvoiceStatus::Void, 777, 'GBP', '2026-10-10');

        // AI — 3 image drafts ($0.04 each), 1 failed, 1 refine ($0.02); 2 trends, 1 idea, 1 brief.
        foreach ([[true, 0.04, 'image.generate'], [true, 0.04, 'image.generate'], [true, 0.04, 'image.generate'], [false, 0, 'image.generate'], [true, 0.02, 'image.edit']] as [$ok, $cost, $method]) {
            $this->aiCall($this->org, $this->acme, $method, $ok, $cost);
        }
        AiTrend::create(['org_id' => $this->org->id, 'client_id' => $this->acme->id, 'title' => 'Trend one', 'confidence' => 0.8]);
        AiTrend::create(['org_id' => $this->org->id, 'client_id' => $this->acme->id, 'title' => 'Trend two', 'confidence' => 0.6]);
        AiPostIdea::create(['org_id' => $this->org->id, 'client_id' => $this->acme->id, 'title' => 'Idea one']);
        DailyBrief::factory()->create(['org_id' => $this->org->id, 'client_id' => $this->acme->id, 'brief_date' => '2026-10-16']);

        // Elsewhere — a busy, rich, different org. Nothing here may ever reach Brightside.
        $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere', 'default_timezone' => 'UTC', 'default_currency' => 'USD']);
        User::factory()->for($this->otherOrg, 'organization')->role(Role::Owner)->create(['name' => 'Aaron Elsewhere']);
        $this->otherClient = Client::factory()->for($this->otherOrg, 'organization')->create(['name' => 'Foreign Client']);
        $otherAccount = $this->account($this->otherClient, Platform::Facebook);

        foreach (range(1, 4) as $day) {
            $post = $this->makePost("other{$day}", $this->otherClient, PostStatus::Published, null, $day, $otherAccount);
            $this->transition($post, PostStatus::Draft, PostStatus::WaitingClient, null, now()->subDays($day)->subHours(5)->toDateTimeString());
            $this->transition($post, PostStatus::WaitingClient, PostStatus::Approved, null, now()->subDays($day)->subHours(4)->toDateTimeString());
            $this->transition($post, PostStatus::Publishing, PostStatus::Published, null, now()->subDays($day)->toDateTimeString());
        }

        $this->makeInvoice($this->otherClient, InvoiceStatus::Paid, 99000, 'USD', '2026-10-10', sentAt: '2026-10-10 09:00:00', paidAt: '2026-10-11 09:00:00');
        $this->makeInvoice($this->otherClient, InvoiceStatus::Sent, 5000, 'GBP', '2026-10-10', dueDate: '2026-10-11', sentAt: '2026-10-10 09:00:00');
        $this->aiCall($this->otherOrg, $this->otherClient, 'image.generate', true, 9.99);
        AiTrend::create(['org_id' => $this->otherOrg->id, 'client_id' => $this->otherClient->id, 'title' => 'Foreign trend', 'confidence' => 0.9]);
    }

    protected function tearDownAnalyticsFixtures(): void
    {
        Carbon::setTestNow();
    }

    protected function staff(string $name, Role $role): User
    {
        return User::factory()->for($this->org, 'organization')->role($role)->create(['name' => $name]);
    }

    protected function account(Client $client, Platform $platform): SocialAccount
    {
        return SocialAccount::factory()->create(['org_id' => $client->org_id, 'client_id' => $client->id, 'platform' => $platform]);
    }

    protected function makePost(string $key, Client $client, PostStatus $status, ?User $creator, int $createdDaysAgo, ?SocialAccount $account = null, ?Campaign $campaign = null): Post
    {
        $post = Post::factory()->for($client)->create([
            'status' => $status,
            'created_by' => $creator?->id,
            'campaign_id' => $campaign?->id,
            'created_at' => now()->subDays($createdDaysAgo),
        ]);

        if ($account !== null) {
            PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
        }

        return $this->posts[$key] = $post;
    }

    protected function transition(Post $post, ?PostStatus $from, PostStatus $to, ?User $user, string $at, ?string $note = null): PostActivityLog
    {
        return PostActivityLog::factory()->create([
            'post_id' => $post->id,
            'user_id' => $user?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_at' => Carbon::parse($at, 'UTC'),
        ]);
    }

    protected function makeInvoice(Client $client, InvoiceStatus $status, float $amount, string $currency, string $issueDate, ?string $dueDate = null, ?string $sentAt = null, ?string $paidAt = null): Invoice
    {
        return Invoice::factory()->for($client)->create([
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'sent_at' => $sentAt,
            'paid_at' => $paidAt,
        ]);
    }

    protected function aiCall(Organization $org, Client $client, string $method, bool $succeeded, float $cost): void
    {
        AiUsageLog::create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'user_id' => null,
            'provider' => 'gemini',
            'model' => 'test-model',
            'method' => $method,
            'succeeded' => $succeeded,
            'error_code' => $succeeded ? null : 'provider_error',
            'cost_estimate' => $cost,
        ]);
    }
}
