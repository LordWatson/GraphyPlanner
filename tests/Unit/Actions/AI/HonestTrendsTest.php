<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecordAiUsageAction;
use App\Contracts\AiProvider;
use App\Contracts\TrendSource;
use App\Models\Client;
use App\Services\AI\AiCallMeter;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiUsageGuard;
use App\Services\AI\Trends\TopPostsTrendSource;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Step M-7.5 (dev plan I-9.5) — honest trends: model guesswork is labelled "AI hypothesis", the
 * client's own top posts come in as a real-signal source, and both are swappable.
 */
class HonestTrendsTest extends TestCase
{
    use RefreshDatabase;

    private function action(AiProvider $ai, TrendSource $signal): DiscoverTrendsAction
    {
        return new DiscoverTrendsAction(
            $ai,
            new AiGuardrailService,
            new AiUsageGuard,
            new AiCallMeter(new RecordAiUsageAction),
            $signal,
        );
    }

    public function test_model_trends_are_labelled_a_hypothesis_and_signal_trends_lead(): void
    {
        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')->andReturn(new AiTrends([
            new AiTrend('Model trend', 'A guess', ['instagram'], 0.4),
        ]));

        $signal = new class implements TrendSource
        {
            public function discover(Client $client, array $platforms, int $limit): AiTrends
            {
                return new AiTrends([new AiTrend('Your best post', 'It did well', ['instagram'], 1.0, 'Your top posts')]);
            }

            public function label(): string
            {
                return 'Your top posts';
            }
        };

        $client = Client::factory()->create();
        $trends = $this->action($ai, $signal)($client, ['instagram'])->trends;

        $bySource = [];
        foreach ($trends as $trend) {
            $bySource[$trend->source ?? 'null'] = $trend->title;
        }

        $this->assertArrayHasKey('AI hypothesis', $bySource);
        $this->assertArrayHasKey('Your top posts', $bySource);
        // Real signal leads the list.
        $this->assertSame('Your top posts', $trends[0]->source);

        // Every surfaced trend records its source in the database.
        $this->assertDatabaseHas('ai_trends', ['client_id' => $client->id, 'title' => 'Model trend', 'source' => 'AI hypothesis']);
        $this->assertDatabaseHas('ai_trends', ['client_id' => $client->id, 'title' => 'Your best post', 'source' => 'Your top posts']);
    }

    public function test_the_total_is_capped_to_the_configured_number(): void
    {
        Config::set('ai.suggestions.per_request', 4);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')->andReturn(new AiTrends(array_map(
            fn (int $i) => new AiTrend("Model {$i}", 'A guess', ['instagram'], 0.4),
            range(1, 10),
        )));

        $signal = new class implements TrendSource
        {
            public function discover(Client $client, array $platforms, int $limit): AiTrends
            {
                return new AiTrends(array_map(
                    fn (int $i) => new AiTrend("Top {$i}", 'Real', ['instagram'], 1.0, 'Your top posts'),
                    range(1, 10),
                ));
            }

            public function label(): string
            {
                return 'Your top posts';
            }
        };

        $trends = $this->action($ai, $signal)(Client::factory()->create(), ['instagram'])->trends;

        $this->assertCount(4, $trends);
        // Up to half from real signal, the rest model.
        $this->assertSame(2, count(array_filter($trends, fn ($t) => $t->source === 'Your top posts')));
    }

    public function test_the_default_signal_source_is_the_clients_top_posts(): void
    {
        $this->assertInstanceOf(TopPostsTrendSource::class, app(TrendSource::class));
    }
}
