<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\GenerateDailyBriefsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Mail\DailyBriefReadyMail;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Organization;
use App\Models\User;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class GenerateDailyBriefsActionTest extends TestCase
{
    use RefreshDatabase;

    private function mockedAction(AiTrends $trends, AiPostIdeas $ideas, int $expectedCalls = 1): GenerateDailyBriefsAction
    {
        $discoverTrends = Mockery::mock(DiscoverTrendsAction::class);
        $discoverTrends->shouldReceive('__invoke')->times($expectedCalls)->andReturn($trends);

        $recommendPosts = Mockery::mock(RecommendPostsAction::class);
        $recommendPosts->shouldReceive('__invoke')->times($expectedCalls)->andReturn($ideas);

        return new GenerateDailyBriefsAction($discoverTrends, $recommendPosts);
    }

    public function test_it_generates_a_client_scoped_brief_for_every_active_client(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $activeClient = Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Active]);
        $pausedClient = Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Paused]);

        $trends = new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: ['instagram'], confidence: 0.8)]);
        $ideas = new AiPostIdeas([new AiPostIdea(title: 'Idea', description: 'Desc')]);

        $action = $this->mockedAction($trends, $ideas, 1);
        $count = $action();

        $this->assertSame(1, $count);

        $brief = DailyBrief::query()->where('client_id', $activeClient->id)->first();
        $this->assertNotNull($brief);
        $this->assertSame('Trend', $brief->trends[0]['title']);
        $this->assertSame('Idea', $brief->ideas[0]['title']);

        $this->assertDatabaseMissing('daily_briefs', ['client_id' => $pausedClient->id]);

        Mail::assertSent(DailyBriefReadyMail::class, fn (DailyBriefReadyMail $mail) => $mail->hasTo($owner->email));
    }

    public function test_it_is_idempotent_for_the_same_client_and_day(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Active]);

        $trends = new AiTrends();
        $ideas = new AiPostIdeas();

        $action = $this->mockedAction($trends, $ideas, 1);

        $first = $action(Carbon::parse('2026-10-14'));
        $second = $action(Carbon::parse('2026-10-14'));

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, DailyBrief::query()->count());
    }

    public function test_it_holds_back_the_email_during_quiet_hours(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-14 23:00:00'));

        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Active]);

        $action = $this->mockedAction(new AiTrends(), new AiPostIdeas(), 1);
        $action();

        Mail::assertNothingSent();
        $brief = DailyBrief::query()->first();
        $this->assertNull($brief->notified_at);

        Carbon::setTestNow();
    }
}
