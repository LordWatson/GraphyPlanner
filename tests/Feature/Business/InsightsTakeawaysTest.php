<?php

namespace Tests\Feature\Business;

use App\Actions\AI\SummarizeInsightsAction;
use App\Contracts\InsightsSummarizer;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step B-8.2 — plain-language takeaways: the summariser caches weekly, meters usage, and degrades
 * to nothing when AI is off (so the page/digest just drop the section).
 */
class InsightsTakeawaysTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): Client
    {
        $org = Organization::factory()->business()->create(['default_timezone' => 'Europe/London']);

        return Client::factory()->for($org, 'organization')->primary()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function insights(): array
    {
        return [
            'windowDays' => 90,
            'publishedTotal' => 8,
            'streak' => 3,
            'goalPerWeek' => 3,
            'engagement' => [
                'measured_channels' => 2,
                'totals' => ['reach' => 1200, 'impressions' => 1500, 'engagement' => 180],
                'engagement_rate' => 12.5,
                'followers' => ['current' => 1100, 'growth' => 100],
                'by_platform' => [
                    ['platform' => 'instagram', 'label' => 'Instagram', 'measured' => 2, 'reach' => 1200, 'engagement' => 180, 'engagement_rate' => 12.5],
                ],
                'top_posts' => [
                    ['caption' => 'Sourdough morning', 'engagement' => 120, 'platforms' => ['instagram']],
                ],
            ],
            'bestTimes' => [
                ['platform' => 'instagram', 'platform_label' => 'Instagram', 'source' => 'data', 'slot' => ['weekday' => 2, 'hour' => 8, 'label' => 'Tue 08:00']],
            ],
        ];
    }

    private function fakeSummarizer(array $lines): object
    {
        return new class($lines) implements InsightsSummarizer
        {
            public int $calls = 0;

            public function __construct(public array $lines) {}

            public function summarizeInsights(Client $brand, array $facts): array
            {
                $this->calls++;

                return $this->lines;
            }
        };
    }

    public function test_it_summarises_meters_and_caches_weekly(): void
    {
        $brand = $this->brand();
        $fake = $this->fakeSummarizer([
            'Your reels reached 3x more people than photos this month.',
            'Tuesday 8am is your best slot.',
        ]);
        $this->app->instance(InsightsSummarizer::class, $fake);

        $action = app(SummarizeInsightsAction::class);

        $first = $action($brand, $this->insights(), 'Europe/London');

        $this->assertTrue($first['available']);
        $this->assertCount(2, $first['takeaways']);
        $this->assertSame('Tuesday 8am is your best slot.', $first['takeaways'][1]['text']);
        $this->assertNotNull($first['generated_at']);

        // One usage row recorded for the generation.
        $this->assertSame(1, AiUsageLog::query()->where('method', 'summarizeInsights')->count());

        // Second call within the week is served from cache — the provider isn't hit again.
        $second = $action($brand, $this->insights(), 'Europe/London');
        $this->assertSame($first['takeaways'], $second['takeaways']);
        $this->assertSame(1, $fake->calls);
        $this->assertSame(1, AiUsageLog::query()->where('method', 'summarizeInsights')->count());
    }

    public function test_it_returns_unavailable_when_the_provider_gives_nothing(): void
    {
        $brand = $this->brand();
        $this->app->instance(InsightsSummarizer::class, $this->fakeSummarizer([]));

        $result = app(SummarizeInsightsAction::class)($brand, $this->insights(), 'Europe/London');

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['takeaways']);
        $this->assertNull($result['generated_at']);
        // An empty summary isn't cached, so it can be retried next view.
        $this->assertNull(app(SummarizeInsightsAction::class)->cached($brand, 'Europe/London'));
    }
}
