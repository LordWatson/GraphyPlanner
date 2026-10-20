<?php

namespace Tests\Feature\Grids;

use App\Enums\GridTileKind;
use App\Models\AiUsageLog;
use App\Models\BrandBrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.12 — the grid's AI help: caption ideas per tile and a balance check. HTTP-faked;
 * nothing is written until "Use"; Brand Brain guardrails apply; each call is logged as usage;
 * off when AI is disabled; the key never reaches a response.
 */
class GridAiAssistTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        Config::set('ai.enabled', true);
        Config::set('ai.deepseek.api_key', 'sk-grid-secret');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');
        BrandBrain::factory()->for($this->client)->create([
            'voice' => ['tone' => 'Warm', 'personality' => 'Friendly', 'do_nots' => ['cheap']],
        ]);
    }

    public function test_caption_ideas_are_suggested_per_tile_and_nothing_is_written(): void
    {
        $plan = $this->planWithTiles(2);
        [$first, $second] = $plan->tiles()->get()->all();
        $first->update(['caption_idea' => 'Original idea']);

        Http::fake(['*/chat/completions' => Http::response($this->chat(['tiles' => [
            ['tile_id' => $first->id, 'captions' => ['Golden hour at the studio.', 'Slow mornings, made by hand.']],
            ['tile_id' => $second->id, 'captions' => ['So cheap you will cry.']],
            ['tile_id' => 999999, 'captions' => ['Not a tile here.']],
        ]]))]);

        $response = $this->actingAs($this->strategist())->postJson(route('grids.ai.captions', $plan))->assertOk();

        $this->assertSame(['Golden hour at the studio.', 'Slow mornings, made by hand.'], $response->json('suggestions.'.$first->id));
        $this->assertNull($response->json('suggestions.'.$second->id), 'A banned phrase is dropped by the guardrail.');
        $this->assertNull($response->json('suggestions.999999'));
        $this->assertSame('Original idea', $first->fresh()?->caption_idea, 'Nothing is written until "Use".');
        $this->assertStringNotContainsString('sk-grid-secret', (string) $response->getContent());

        Http::assertSent(fn (Request $request) => str_contains((string) json_encode($request->data()), 'tile_id '.$first->id));
        $this->assertSame(1, AiUsageLog::query()->where('method', 'suggestTileCaptions')->where('client_id', $this->client->id)->count());

        // "Use" is an ordinary tile save.
        $this->actingAs($this->strategist())->put(route('grids.tiles.update', [$plan, $first]), ['caption_idea' => 'Golden hour at the studio.'])->assertSessionHasNoErrors();
        $this->assertSame('Golden hour at the studio.', $first->fresh()?->caption_idea);
    }

    public function test_the_balance_check_returns_notes_with_local_colours_and_moves_nothing(): void
    {
        $plan = $this->planWithTiles(3);
        $this->addTile($plan, GridTileKind::Placeholder, attributes: ['placeholder_colour' => 'lilac', 'team_note' => 'Product shot']);
        $order = $plan->tiles()->pluck('id')->all();

        Http::fake(['*/chat/completions' => Http::response($this->chat(['notes' => [
            'Three product shots in a row on row 1. Try moving tile 2 down.',
            'The lilac placeholder sits well against the teal.',
        ]]))]);

        $response = $this->actingAs($this->owner())->postJson(route('grids.ai.balance', $plan))->assertOk();

        $this->assertCount(2, $response->json('notes'));
        $this->assertSame($order, $plan->tiles()->pluck('id')->all());
        Http::assertSent(fn (Request $request) => str_contains((string) json_encode($request->data()), 'Soft lilac'));
        $this->assertSame(1, AiUsageLog::query()->where('method', 'checkGridBalance')->where('succeeded', true)->count());
    }

    public function test_a_vendor_failure_returns_nothing_and_logs_a_failed_call(): void
    {
        $plan = $this->planWithTiles(1);
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'down']], 500)]);

        $this->actingAs($this->owner())->postJson(route('grids.ai.captions', $plan))->assertOk()->assertJson(['suggestions' => []]);
        $this->assertSame(1, AiUsageLog::query()->where('succeeded', false)->count());
    }

    public function test_ai_is_off_when_disabled_and_only_for_owners_and_strategists(): void
    {
        $plan = $this->planWithTiles(1);
        Http::fake();

        $this->actingAs($this->designer())->postJson(route('grids.ai.captions', $plan))->assertForbidden();
        $this->actingAs($this->reviewer)->postJson(route('grids.ai.balance', $plan))->assertForbidden();

        Config::set('ai.enabled', false);
        $this->actingAs($this->owner())->postJson(route('grids.ai.captions', $plan))->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function chat(array $json): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($json)]]]];
    }
}
