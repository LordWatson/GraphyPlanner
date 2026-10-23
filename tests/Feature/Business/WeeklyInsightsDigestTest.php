<?php

namespace Tests\Feature\Business;

use App\Actions\Business\SendWeeklyInsightsDigestAction;
use App\Contracts\InsightsSummarizer;
use App\Enums\Platform;
use App\Enums\Role;
use App\Mail\WeeklyInsightsDigestMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step B-8.2 — the weekly Insights email digest: goes to Owners/Managers of a business workspace
 * with activity, carries the takeaways, and is never sent to an org that opted out (or has nothing
 * published, or is an agency).
 */
class WeeklyInsightsDigestTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    private function fakeTakeaways(array $lines): void
    {
        $this->app->instance(InsightsSummarizer::class, new class($lines) implements InsightsSummarizer
        {
            public function __construct(public array $lines) {}

            public function summarizeInsights(Client $brand, array $facts): array
            {
                return $this->lines;
            }
        });
    }

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function activeBusiness(array $orgOverrides = []): array
    {
        $org = Organization::factory()->business()->create([
            'default_timezone' => 'Europe/London',
            'onboarding_completed_at' => now(),
            ...$orgOverrides,
        ]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create(['email_verified_at' => now()]);

        $account = $this->engagementAccount($brand, Platform::Instagram, 'Europe/London');
        $this->publishedPost($brand, [$account], now()->subDays(2));

        return [$org, $brand, $owner];
    }

    public function test_it_emails_owners_with_the_takeaways_when_there_is_activity(): void
    {
        Mail::fake();
        $this->fakeTakeaways(['Your reach grew 20% this week.']);
        [$org, , $owner] = $this->activeBusiness();

        $sent = app(SendWeeklyInsightsDigestAction::class)($org);

        $this->assertTrue($sent);
        Mail::assertQueued(WeeklyInsightsDigestMail::class, function (WeeklyInsightsDigestMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && $mail->takeaways === ['Your reach grew 20% this week.']
                && $mail->headline['published'] === 1;
        });
    }

    public function test_it_does_not_send_when_the_workspace_opted_out(): void
    {
        Mail::fake();
        $this->fakeTakeaways(['anything']);
        [$org] = $this->activeBusiness(['insights_digest_opt_out' => true]);

        $this->assertFalse(app(SendWeeklyInsightsDigestAction::class)($org));
        Mail::assertNothingQueued();
    }

    public function test_it_does_not_send_without_any_published_posts(): void
    {
        Mail::fake();
        $this->fakeTakeaways(['anything']);

        $org = Organization::factory()->business()->create([
            'default_timezone' => 'Europe/London',
            'onboarding_completed_at' => now(),
        ]);
        Client::factory()->for($org, 'organization')->primary()->create();
        User::factory()->for($org, 'organization')->role(Role::Owner)->create(['email_verified_at' => now()]);

        $this->assertFalse(app(SendWeeklyInsightsDigestAction::class)($org));
        Mail::assertNothingQueued();
    }

    public function test_an_agency_never_receives_the_digest(): void
    {
        Mail::fake();
        $org = Organization::factory()->create(['onboarding_completed_at' => now()]);

        $this->assertFalse(app(SendWeeklyInsightsDigestAction::class)($org));
        Mail::assertNothingQueued();
    }
}
